<?php

namespace Symbiote\MicroBlog\Service;


use Exception;

use SilverStripe\Security\Member;
use Symbiote\MicroBlog\Model\MicroPost;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Security;
use Symbiote\MicroBlog\Model\MicroPostVote;
use SilverStripe\Core\Convert;
use SilverStripe\Security\PermissionFailureException;
use Symbiote\MicroBlog\Model\Friendship;
use SilverStripe\Assets\File;
use Symbiote\MicroBlog\Extension\MicroBlogMember;
use Symbiote\MicroBlog\Extension\TaggableExtension;
use SilverStripe\ORM\SS_List;
use SilverStripe\Assets\Upload;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Assets\Image;

/**
 * @author marcus@symbiote.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class MicroBlogService
{
    /**
     * @var QueuedJobService
     */
    public $queuedJobService;

    /**
     *
     * @var NotificationService
     */
    public $notificationService;

    /**
     * @var TransactionManager
     */
    public $transactionManager;

    /**
     * Do we allow anonymous posting?
     *
     * @var boolean
     */
    public $allowAnonymousPosts = false;

    /**
     * 
     * 
     * Are users allowed to vote multiple times on a post?
     * 
     * @var boolean
     */
    public $singleVotes = false;

    /**
     * Must users have a vote balance?
     *
     * @var boolean
     */
    public $requireVoteBalance = true;

    /**
     * Should all posts be analysed _after_ the http request that creates them
     * is completed (ie async)
     * 
     * Should the processing of post content be done in a threaded manner? Generally not needed
     *
     * @var boolean
     */
    public $postProcess = false;

    /**
     * The list of properties that a user can set when creating a post
     *
     * @var array
     */
    public $allowedProperties = array('Title' => true, 'PostType' => true, 'DisableReplies' => true);

    /**
     * The items that we can sort things by
     *
     * @var array
     */
    public $canSort = array('WilsonRating', 'ID', 'Created', 'Up', 'Down', 'ActiveRating', 'PositiveRating');

    /**
     * A map of PostType => age_in_seconds 
     * 
     * Allows certain types of posts to be filtered out after a particular age
     * 
     * @var array
     */
    public $typeAge = array();

    /**
     * A request length list of actions that users have taken
     *
     * @var array
     */
    protected $userActions = array();

    private static $dependencies = [
        'transactionManager' => "%$" . TransactionManager::class,
    ];

    public function webEnabledMethods()
    {
        return array(
            // returns top level posts
            'posts'    => ['type' => 'GET', 'call' => 'globalFeed', 'public' => true],
            'upload' => 'POST',
            'unreadPosts'        => 'GET',
            'createPost'        => 'POST',
            'deletePost'        => 'POST',
            'hidePost'          => 'POST',
            'vote'                => 'POST',
            // any posts for a given ID range
            'updates'    => ['type' => 'GET', 'call' => 'getStatusUpdates'],
            // retrieves just posts by people I follow
            'timeline'        => ['type' => 'GET', 'call' => 'getTimeline'],
            'addFriendship'        => 'POST',
            'removeFriendship'    => 'POST',
            'rawPost'            => 'GET',
            'savePost'            => 'POST',
            'findMember'        => 'GET',
            'fileLookup'        => 'GET',
        );
    }

    public function getUserActions()
    {
        return $this->userActions;
    }

    public function unreadPosts($target = null)
    {
        $member = Security::getCurrentUser();
        if (!$member || !$member->ID) {
            return array();
        }
        return $member->getUnreadPosts($target);
    }

    /**
     * Creates a new post for the given member
     *
     * @param Member $member
     *			The member creating the post. Will default to the calling member if not specified
     * @param string $content
     *			The content being loaded into the post
     * @param array $properties
     *			Additional properties to be bound into the post. 
     * @param int $parentId
     *			The ID of a micropost that is considered the 'parent' of this post
     * @param mixed $target
     *			The "target" of this post; may be a data object (ie context of the post) or a user/group
     * @param array $to
     *			The people/groups this post is being sent to. This is an array of
     *			- logged_in: boolean (logged in users; uses a system config setting to determine which group represents 'logged in'
     *			- members: an array, or comma separated string, of member IDs
     *			- groups: an array, or comma separated string, of group IDs
     * @return MicroPost 
     */
    public function createPost($content, $properties = [], $parentId = 0, $target = null, $to = null)
    {
        if (is_string($to)) {
            $to = $this->arrayFromString($to);
        }

        // backwards compatible 
        if (is_string($properties)) {
            $properties = ['Title' => $properties];
        }

        if (!is_array($properties)) {
            $properties = [];
        }

        $member = Security::getCurrentUser();

        if (!$member->exists() && !$this->allowAnonymousPosts) {
            throw new Exception("Anonymous posting disallowed");
        }

        $post = MicroPost::create();
        $post->Content = $content;

        if ($properties && count($properties)) {
            foreach ($properties as $field => $value) {
                if (isset($this->allowedProperties[$field])) {
                    $post->$field = $value;
                }
            }
        }

        $post->OwnerID = $member->ID;
        $post->Target = $target;

        if ($target) {
            $targetObject = $post->getPostTarget();
            if ($targetObject && $targetObject->canView()) {
                $link = $targetObject instanceof File ? 'microblog/media/' . $targetObject->ID : ($targetObject->hasMethod('Link') ? $targetObject->Link() : '');
                $post->TargetInfo = \json_encode([
                    'Title' => $targetObject->Title,
                    'Link' => $link,
                ]);
            }
        }

        $parentId = $properties['ParentID'] ?? $parentId;

        if ($parentId) {
            $parent = MicroPost::get()->byID($parentId);
            if ($parent && $parent->canView()) {
                $post->ParentID = $parentId;
                $post->ThreadID = $parent->ThreadID;
                $post->Target = $parent->Target;
                $post->TargetInfo = $parent->TargetInfo;
                $this->transactionManager->runAsAdmin(function () use ($parent) {
                    $parent->NumChildren = $parent->NumChildren + 1;
                    $parent->write();
                });
            }
        }

        if (isset($to['public'])) {
            $post->PublicAccess = (bool) $to['public'];
        }

        $post->write();

        // if we're a good poster, scan its content, otherwise post process it for spam
        if ($member->Balance >= MicroBlogMember::BALANCE_THRESHOLD) {
            $post->analyseContent();
            $post->write();
        } else {
            // todo SPAM CHECK
            // $this->queuedJobService->queueJob(new ProcessPostJob($post));
        }

        // set its thread ID
        if (!$post->ParentID) {
            $post->ThreadID = $post->ID;
            $post->write();
        }

        if ($post->ID != $post->ThreadID) {
            $thread = MicroPost::get()->byID($post->ThreadID);
            if ($thread && $thread->canView()) {
                $owner = $thread->Owner();
                $this->transactionManager->run(function () use ($post, $thread) {
                    $thread->NumReplies += 1;
                    $thread->write();
                }, $owner);
            }
        }

        $this->rewardMember($member, 2);

        if ($to) {
            $post->giveAccessTo($to);
        }

        // we stick this in here so the UI can update...
        $post->RemainingVotes = $member->VotesToGive;

        $post->extend('onCreated', $member, $target);
        if ($this->notificationService) {
            $this->notificationService->notify('MICRO_POST_CREATED', $post);
        }

        if (!$post->ParentID) {
            // ensures there's a value set before it gets output.
            $post->ParentID = 0;
        }

        return $post->toFilteredMap();
    }

    /**
     * Gets the raw post if allowed
     * 
     * @param int $id 
     */
    public function rawPost($id)
    {
        $item = MicroPost::get()->byID($id);
        if ($item && $item->canEdit()) {
            return $item;
        }
    }

    /**
     * Save the post
     * 
     * @param DataObject $post
     * @param type $data 
     */
    public function savePost(DataObject $post, $data)
    {
        if ($post->canEdit()) {
            $post->update($data);
            if (Security::getCurrentUser()->Balance >= MicroBlogMember::BALANCE_THRESHOLD) {
                $post->analyseContent();
                $post->write();
            } else {
                // todo spam check
                $this->queuedJobService->queueJob(new ProcessPostJob($post));
            }
            return $post->toFilteredMap();
        }
    }

    /**
     * Extracts tags from an object's content where the tag is preceded by a #
     * 
     * @param MicroPost $object 
     * 
     */
    public function extractTags(DataObject $object, $field = 'Content')
    {
        if (!$object->hasExtension(TaggableExtension::class)) {
            return array();
        }
        $content = $object->$field;

        if (preg_match_all('/#([a-z0-9_-]+)/is', $content, $matches)) {
            $object->tag($matches[1], true);
        }

        return $object->Tags();
    }

    /**
     * Reward a member with a number of votes to be given
     * @param type $member
     * @param type $votes 
     */
    public function rewardMember($member, $votes)
    {
        $member->VotesToGive += $votes;
        $this->transactionManager->run(function () use ($member) {
            $member->write();
        }, $member);
    }

    protected function arrayFromString($filter)
    {
        $keypairs = implode("\n", explode(';', $filter));
        $arr = parse_ini_string($keypairs);
        // $arr = [];
        // foreach ($keypairs as $pair) {
        //     list($key, $value) = \split("/=/", $pair, 2);
        //     $arr[$key] = $value;
        // }
        return $arr;
    }

    protected function packagePostList(SS_List $posts, $number = 50, $fromNumber = 0)
    {
        $number = min($number, 50);

        $totalPosts = $posts->count();

        $fromNumber = (int) $fromNumber;
        $number = (int) $number;

        $limit = "$fromNumber, $number";
        $posts = $posts->limit($limit)->filterByCallback(function ($post) {
            return $post->canView();
        });

        $members = [];

        $postIds = $posts->column('OwnerID');

        if (count($postIds)) {
            $members = Member::get()->filter([
                'ID' => $posts->column('OwnerID'),
            ])->filterByCallback(function ($m) {
                return $m->canView();
            });
        }

        $response = [
            'posts' => [],
            'remaining' => $totalPosts,
            'members' => [],
        ];

        foreach ($posts as $item) {
            $response['posts'][] = $item->toFilteredMap();
        }
        foreach ($members as $m) {
            $response['members'][] = $m->toFilteredMap();
        }

        return $response;
    }

    /**
     * Get all posts that the current user has access to
     *
     * @param type $number 
     */
    public function globalFeed($filter = array(), $orderBy = 'ID DESC', $number = 10, $fromNumber = 0, $before = null, $markViewed = true)
    {
        if (is_string($filter)) {
            $filter = $this->arrayFromString($filter);
        }
        $number = (int) $number;

        // if (!count($filter)) {
        //     $filter = array('ParentID' => 0);
        // }
        $filter['Deleted'] = 0;

        $items = MicroPost::get()->filter($filter)->sort($orderBy);

        if ($before) {
            $before = (int) $before;
            $items = $items->filter('ID:LessThan', $before);
        }

        if ($markViewed) {
            $this->recordUserAction();
        }
        $items = $this->updatePostList($items);
        return $this->packagePostList($items, $number, $fromNumber);
    }

    /**
     * Gets all the status updates for a particular user before a given time
     * 
     * @param array $filter
     *			The specific filter flags, or member object, to get status updates from
     * @param type $sortBy
     *			The order in which the items should be sorted
     * @param type $since
     *			The ID after which to retrieve 
     * @param boolean $before
     *			The ID before which to retrieve
     * @param boolean $topLevelOnly
     *			Whether to retrieve top-level posts only
     * @param array $tags
     *			A set of tags to filter posts by
     * @param int $offset
     *			Offset to start returning results by
     * @param int $number
     *			How many results to return
     *			
     */
    public function getStatusUpdates($filter = array(), $sortBy = 'ID', $since = 0, $before = false, $topLevelOnly = true, $tags = array(), $offset = 0, $number = 10)
    {
        // legacy support; this should really be performed from the calling code to use its own filter logic.
        if ($filter instanceof Member) {
            $userIds[] = $filter->ID;
            $filter = array(
                'ThreadOwnerID'        => $userIds,
            );
        }
        if (!$filter) {
            $filter = array();
        }
        return $this->microPostList($filter, $sortBy, $since, $before, $topLevelOnly, $tags, $offset, $number);
    }

    /**
     * Gets all the updates for a given user's list of followers for a given time
     * period
     *
     * @param type $member
     * @param type $beforeTime
     * @param type $number 
     */
    public function getTimeline(DataObject $member, $sortBy = 'ID',  $since = 0, $before = false, $topLevelOnly = true, $tags = array(), $offset = 0, $number = 10)
    {
        $following = $this->friendsList($member);

        $number = (int) $number;
        $userIds = array();
        if ($following) {
            $userIds = $following->map('OtherID', 'OtherID');
            $userIds = $userIds->toArray();
        }

        $userIds[] = $member->ID;

        $filter = array(
            'OwnerID' => $userIds,
        );

        if (is_string($tags)) {
            $tags = explode(',', $tags);
        }
        return $this->microPostList($filter, $sortBy, $since, $before, $topLevelOnly, $tags, $offset, $number);
    }

    /**
     * Get the list of replies to a particular post
     * 
     * @param DataObject $to
     * @param type $since
     * @param type $beforePost
     * @param type $topLevelOnly
     * @param type $number 
     * 
     * @return DataList
     */
    public function getRepliesTo(DataObject $to, $sortBy = 'ID', $since = 0, $before = false, $topLevelOnly = false, $tags = array(), $offset = 0, $number = 100)
    {
        $filter = array(
            'ParentID'            => $to->ID,
        );
        return $this->microPostList($filter, $sortBy, $since, $before, $topLevelOnly, $tags, $offset, $number);
    }

    /**
     * Create a list of posts depending on a filter and time range
     * 
     * @param array $filter
     *			
     * @param int $since
     *				The ID after which to get posts 
     * @param int $before
     *				The ID or pagination offset from which to get posts before. 
     * @param type $topLevelOnly
     *              Only retrieve the top level of posts. 
     * @param array $tags
     *			A set of tags to filter posts by
     * @param int $offset
     *			Offset to start returning results by
     * @param int $number
     *			How many results to return
     * 
     * @return DataList 
     */
    public function microPostList($filter, $sortBy = 'ID', $since = 0, $before = false, $topLevelOnly = true, $tags = array(), $offset = 0, $number = 10)
    {
        if ($topLevelOnly) {
            $filter['ParentID'] = '0';
        }

        $filter['Deleted'] = 0;

        if ($since) {
            $filter['ID:GreaterThan'] = $since;
        }

        if ($before !== false) {
            $before = (int) $before;
            $filter['ID:LessThan'] = $before;
        }

        if (!isset($filter['Hidden'])) {
            $filter['Hidden'] = 0;
        }

        $sort = array();

        if (is_string($sortBy)) {
            if (in_array($sortBy, $this->canSort)) {
                $sort[$sortBy] = 'DESC';
            }

            // final sort as a tie breaker
            $sort['ID'] = 'DESC';
        } else if (is_array($sortBy)) {
            // $sort = $sortBy;
            foreach ($sortBy as $sortKey => $sortDir) {
                if (in_array($sortKey, $this->canSort)) {
                    $sort[$sortKey] = $sortDir;
                }
            }
        } else {
            $sort = array('ID' => 'DESC');
        }

        $offset = (int) $offset;
        $limit = $number ? $offset . ', ' . (int) $number : '';

        if (count($tags)) {
            $filter['Tags.Title'] = $tags;
        }


        $this->recordUserAction();
        $list = MicroPost::get()->filter($filter)->sort($sort)->limit($limit);
        $list = $this->updatePostList($list);

        // if we're only allowing singe votes, we need to get _all_ the current user's votes and
        // mark the individual posts that have been voted on; this allows the toggling 
        // of the vote options
        if ($this->singleVotes && Security::getCurrentUser()) {
            $ids = $list->column('ID');
            $votes = MicroPostVote::get()->filter(array(
                'UserID'        => Security::getCurrentUser()->ID,
                'PostID'        => $ids,
            ));
            $map = $votes->map('PostID', 'Direction')->toArray();
            foreach ($list as $post) {
                if (isset($map[$post->ID])) {
                    $post->UserVote = $map[$post->ID] > 0 ? 'upvote' : 'downvote';
                }
            }
        }

        return $this->packagePostList($list, $number);
    }

    protected function updatePostList($list)
    {
        if (count($this->typeAge)) {
            // apply post type specific age filtering. 
            $typeParts = array(
                'null'        => '"PostType" IS NULL',
            );
            foreach ($this->typeAge as $type => $age) {
                $laterThan = date('Y-m-d H:i:s', time() - $age);
                if (strtolower($type) == 'null') {
                    $typeParts['null'] = '"PostType" IS NULL AND "MicroPost"."Created" >= \'' . $laterThan . '\'';
                } else {
                    $typeParts[$type] = '"PostType" = \'' . Convert::raw2sql($type) . '\' AND "MicroPost"."Created" > \'' . $laterThan . '\'';
                }
            }
            $typeWhere = '(' . implode(' OR ', $typeParts) . ')';
            $list = $list->where($typeWhere);
        }

        return $list;
    }


    protected function recordUserAction($member = null)
    {
        if (!$member) {
            $member = Security::getCurrentUser();
        }

        if ($member && $member->ID) {
            $this->userActions[$member->ID] = $member->ID;
        }
    }

    /**
     * Search for a member or two
     * 
     * @param string $searchTerm 
     * @return DataList
     */
    public function findMember($searchTerm)
    {
        $term = Convert::raw2sql($searchTerm);
        $current = (int) Security::getCurrentUser()->ID;
        $filter = '("Username" LIKE \'' . $term . '%\' OR "FirstName" LIKE \'' . $term . '%\' OR "Surname" LIKE \'' . $term . '%\') AND "ID" <> ' . $current;

        $items = DataList::create('Member')->where($filter)->filterByCallback(function ($o) {
            return $o->canView();
        });

        return $items;
    }

    /**
     * Create a friendship relationship object
     * 
     * @param DataObject $member
     *				"me", as in the person who triggered the follow
     * @param DataObject $followed
     *				"them", the person "me" is wanting to add 
     * @return \Friendship
     * @throws PermissionDeniedException 
     */
    public function addFriendship(DataObject $member, DataObject $followed)
    {
        if (!$member || !$followed) {
            throw new PermissionFailureException('Cannot read those users');
        }

        if ($member->ID != Security::getCurrentUser()->ID) {
            throw new PermissionFailureException('Cannot create a friendship for that user');
        }

        $existing = Friendship::get()->filter(array(
            'InitiatorID'        => $member->ID,
            'OtherID'            => $followed->ID,
        ))->first();

        if ($existing) {
            return $existing;
        }

        // otherwise, we have a new one!
        $friendship = new Friendship;
        $friendship->InitiatorID = $member->ID;
        $friendship->OtherID = $followed->ID;

        // we add the initiator into the 

        // lets see if we have the reciprocal; if so, we can mark these as verified 
        $reciprocal = $friendship->reciprocal();

        // so we definitely add the 'member' to the 'followers' group of $followed
        $followers = $followed->getGroupFor('Followers');
        $followers->Members()->add($member);

        if ($reciprocal) {
            $reciprocal->Status = 'Approved';
            $reciprocal->write();

            $friendship->Status = 'Approved';

            // add to each other's friends groups
            $friends = $followed->getGroupFor('Friends');
            $friends->Members()->add($member);


            $friends = $member->getGroupFor('Friends');
            $friends->Members()->add($followed);
        }

        $friendship->write();
        return $friendship;
    }

    /**
     * Remove a friendship object
     * @param DataObject $relationship 
     */
    public function removeFriendship(DataObject $relationship)
    {
        if ($relationship && $relationship->canDelete()) {

            // need to remove this user from the 'other's followers group and friends group
            // if needbe
            if ($relationship->Status == 'Approved') {
                $reciprocal = $relationship->reciprocal();
                if ($reciprocal) {
                    // set it back to pending
                    $reciprocal->Status = 'Pending';
                    $reciprocal->write();
                }

                $friends = $relationship->Other()->getGroupFor(MicroBlogMember::FRIENDS);
                $relationship->Initiator()->Groups()->remove($friends);

                $friends = $relationship->Initiator()->getGroupFor(MicroBlogMember::FRIENDS);
                $relationship->Other()->Groups()->remove($friends);
            }

            $followers = $relationship->Other()->getGroupFor(MicroBlogMember::FOLLOWERS);
            $relationship->Initiator()->Groups()->remove($followers);

            $relationship->delete();
            return $relationship;
        }
    }

    /** 
     * Get a list of friends for a particular member
     * 
     * @param DataObject $member
     * @return DataList
     */
    public function friendsList(DataObject $member)
    {
        if (!$member) {
            return;
        }
        $list = Friendship::get()->filter(array('InitiatorID' => $member->ID));
        return $list;
    }

    /**
     * Delete a post
     * 
     * @param DataObject $post 
     */
    public function deletePost($postId)
    {
        if (!$postId) {
            return;
        }
        $post = MicroPost::get()->byID($postId);
        if ($post && $post->canDelete()) {
            $post->delete();
        }

        return $post;
    }

    public function hidePost(DataObject $post)
    {
        if (!$post) {
            return;
        }
        if ($post->canDelete()) {
            $post->Hidden = true;
            $post->write();
        }

        return $post;
    }

    /**
     * Vote for a particular post
     * 
     * @param DataObject $post 
     */
    public function vote(DataObject $post, $dir = 1)
    {
        $member = Security::getCurrentUser();

        if ($this->requireVoteBalance && $member->VotesToGive <= 0) {
            $post->RemainingVotes = 0;
            return $post;
        }

        // we allow multiple votes - as many as the user has to give! unless
        // configured not to...
        $currentVote = null;

        if ($this->singleVotes) {
            $votes = $post->currentVotesByUser();
            if (count($votes)) {
                $currentVote = $votes[0];
            }
        }

        if (!$currentVote) {
            $currentVote = MicroPostVote::create();
            $currentVote->UserID = $member->ID;
            $currentVote->PostID = $post->ID;
        }

        $currentVote->Direction = $dir > 0 ? 1 : -1;
        $currentVote->write();

        $list = MicroPostVote::get();

        $upList = $list->filter(array('PostID' => $post->ID, 'Direction' => 1));
        $post->Up = $upList->count();

        $downList = $list->filter(array('PostID' => $post->ID, 'Direction' => -1));
        $post->Down = $downList->count();

        $owner = $post->Owner();
        if (!$post->OwnerID || !$owner || !$owner->exists()) {
            $owner = Security::findAnAdministrator();
        }

        // write the post as the owner, and calculate some changes for the author
        $this->transactionManager->run(function () use ($post, $currentVote, $member) {
            $author = $post->Owner();
            if ($author && $author->exists() && $author->ID != $member->ID) {
                if ($currentVote->Direction > 0) {
                    $author->Up += 1;
                } else {
                    $author->Down += 1;
                }
                $author->write();
            }
            $post->write();
        }, $owner);

        $this->rewardMember($member, -1);
        $post->RemainingVotes = $member->VotesToGive;

        return $post->toFilteredMap();
    }

    public function upload($file)
    {
        $member = Security::getCurrentUser();
        if (!$member) {
            return;
        }

        // if (!isset($file[]))

        $relationClass = File::get_class_for_file_extension(
            File::get_file_extension($file['name'])
        );

        $assetObject = Injector::inst()->create($relationClass ? $relationClass : File::class);

        Upload::create()->loadIntoFile($file, $assetObject, 'user-files/' . $member->ID);
        if ($assetObject && $assetObject->ID) {
            $link = $assetObject->getURL();
            $mediaLink = '';
            if ($assetObject instanceof Image) {
                $pageLink = 'microblog/media/' . $assetObject->ID;
                $mediaLink = '[![](' . $link . ')](' . $pageLink . ')';
            } else {
                $mediaLink = '[' . $assetObject->Title .'](' . $link . ')';
            }
            
            return [
                'Title' => $assetObject->Title,
                'Type' => $assetObject instanceof Image ? 'image' : 'file',
                'ID' => $assetObject->ID,
                'MediaLink' => $mediaLink,
                'Link'  => $link
            ];
        }

        return $assetObject;
    }

    /**
     * Lookup files that you have uploaded
     * 
     * @param string $fileId
     */
    public function fileLookup($fileId)
    {
        $member = Security::getCurrentUser();
        if (!$member) {
            return;
        }

        $file = File::get()->filter(array('ID' => $fileId, 'OwnerID' => $member->ID))->first();
        if ($file && $file->ID) {
            return array(
                'Title'        => $file->Title,
                'Link'        => $file->getAbsoluteURL(),
                'IsImage'    => $file instanceof Image,
                'ID'        => $file->ID,
            );
        }
    }
}

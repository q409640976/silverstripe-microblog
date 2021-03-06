import { Action } from "redux";

export const ActionType = {
    STORE_LOAD: "STORE_LOAD",

    SET_USER: "SET_USER",
    SET_USERS: "SET_USERS",
    // from example module, please delete!
    START_POSTS_LOAD: "START_POSTS_LOAD",
    LOAD_POSTS: "LOAD_POSTS",

    UPDATING_POST: "UPDATING_POST",

    REPLY_TO_POST: "REPLY_TO_POST",
    EDIT_POST: "EDIT_POST",
    DELETE_POST: "DELETE_POST",

    FILTER_COUNT: "FILTER_COUNT",
}

export interface BaseAction extends Action {
    payload: any
}
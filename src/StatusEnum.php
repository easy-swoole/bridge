<?php

namespace EasySwoole\Bridge;

enum StatusEnum
{
    case CONNECT_ERROR;

    case SEND_ERROR;

    case RECEIVE_ERROR;

    case INVALID_PACKAGE;

    case REQUEST_SERIALIZE_ERROR;

    case REQUEST_TOO_LARGE;

    case RESPONSE_SERIALIZE_ERROR;

    case RESPONSE_TOO_LARGE;

    case COMMAND_NOT_EXIST;

    case COMMAND_EXEC_ERROR;

    case SUCCESS;

    case UN_INIT;
}

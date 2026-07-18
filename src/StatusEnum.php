<?php

namespace EasySwoole\Bridge;

enum StatusEnum
{
    case CONNECT_ERROR;

    case PACKAGE_ERROR;

    case COMMAND_NOT_EXIST;

    case COMMAND_EXEC_ERROR;

    case SUCCESS;

    case UN_INIT;
}

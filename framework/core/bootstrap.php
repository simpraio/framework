<?php

declare(strict_types=1);

$core = __DIR__;

require_once $core . '/config/Cast.php';
require_once $core . '/config/Map.php';
require_once $core . '/config/loader/Env.php';
require_once $core . '/config/loader/Files.php';
require_once $core . '/config/Compiler.php';
require_once $core . '/config/dto/Project.php';
require_once $core . '/config/dto/Route.php';
require_once $core . '/config/dto/Language.php';
require_once $core . '/config/dto/Database.php';
require_once $core . '/config/dto/Session.php';
require_once $core . '/config/dto/Log.php';
require_once $core . '/config/Config.php';

require_once $core . '/extension/Boot.php';
require_once $core . '/extension/Bootable.php';
require_once $core . '/extension/Hook.php';
require_once $core . '/extension/Contributor.php';
require_once $core . '/extension/Loader.php';

require_once $core . '/http/HttpException.php';
require_once $core . '/http/RequestPayload.php';
require_once $core . '/http/Request.php';
require_once $core . '/http/Response.php';
require_once $core . '/http/Route.php';
require_once $core . '/http/RouteSegment.php';
require_once $core . '/http/Aliases.php';
require_once $core . '/http/Router.php';

require_once $core . '/cache/Memory.php';
require_once $core . '/cache/Apcu.php';
require_once $core . '/cache/Cache.php';

require_once $core . '/log/Writer.php';
require_once $core . '/session/Store.php';
require_once $core . '/db/Connection.php';
require_once $core . '/db/Db.php';

require_once $core . '/tools/Identifier.php';
require_once $core . '/tools/Format.php';
require_once $core . '/tools/Assets.php';

require_once $core . '/Paths.php';
require_once $core . '/Instance.php';
require_once $core . '/Container.php';
require_once $core . '/Template.php';
require_once $core . '/View.php';
require_once $core . '/Controller.php';
require_once $core . '/Extensions.php';
require_once $core . '/ErrorPage.php';
require_once $core . '/ErrorHandler.php';
require_once $core . '/Log.php';
require_once $core . '/Session.php';
require_once $core . '/Request.php';
require_once $core . '/Bundle.php';
require_once $core . '/Kernel.php';

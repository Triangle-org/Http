<?php declare(strict_types=1);

/**
 * @package     Triangle HTTP Component
 * @link        https://github.com/Triangle-org/Http
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2023-2024 Triangle Framework Team
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as published
 *              by the Free Software Foundation, either version 3 of the License, or
 *              (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <triangle@localzet.com>
 */

namespace Triangle\Http;

use Closure;
use ErrorException;
use Exception;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use localzet\Server;
use localzet\Server\Connection\TcpConnection;
use localzet\Server\Protocols\Http;
use Monolog\Logger;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use think\Model as ThinkModel;
use Throwable;
use Triangle\Engine\Autoload;
use Triangle\Engine\Config;
use Triangle\Engine\Context;
use Triangle\Engine\Path;
use Triangle\Engine\Plugin;
use Triangle\Exception\ExceptionHandler;
use Triangle\Exception\ExceptionHandlerInterface;
use Triangle\Exception\InputTypeException;
use Triangle\Exception\MissingInputException;
use Triangle\Middleware\Bootstrap as Middleware;
use Triangle\Middleware\MiddlewareInterface;
use Triangle\Router;
use Triangle\Router\Dispatcher;
use Triangle\Router\RouteObject;
use function array_merge;
use function array_pop;
use function array_reduce;
use function array_splice;
use function array_values;
use function class_exists;
use function clearstatcache;
use function count;
use function current;
use function end;
use function explode;
use function get_class_methods;
use function gettype;
use function implode;
use function in_array;
use function is_a;
use function is_array;
use function is_dir;
use function is_file;
use function is_numeric;
use function is_string;
use function key;
use function method_exists;
use function ob_get_clean;
use function ob_start;
use function pathinfo;
use function scandir;
use function str_replace;
use function strtolower;
use function substr;
use function trim;

/**
 * Class App
 */
class App
{
    /**
     * @var callable[]
     */
    protected static array $callbacks = [];

    /**
     * @var Server|null
     */
    protected static ?Server $server = null;

    /**
     * @var Logger|null
     */
    protected static ?Logger $logger = null;


    /**
     * @var string|null
     */
    protected static ?string $requestClass = null;

    /**
     * @param string $requestClass
     * @param Logger $logger
     * @param string|null $basePath
     * @param string|null $appPath
     * @param string|null $configPath
     * @param string|null $publicPath
     * @param string|null $runtimePath
     */
    public function __construct(
        string $requestClass,
        Logger $logger,
        string $basePath = null,
        string $appPath = null,
        string $configPath = null,
        string $publicPath = null,
        string $runtimePath = null,
    )
    {
        static::$requestClass = $requestClass;
        static::$logger = $logger;

        new Path(
            basePath: $basePath ?? Path::basePath(),
            configPath: $configPath ?? Path::configPath(),
            appPath: $appPath ?? config('server.app_path', config('app.app_path', Path::appPath())),
            publicPath: $publicPath ?? config('server.public_path', config('app.public_path', Path::publicPath())),
            runtimePath: $runtimePath ?? config('server.runtime_path', config('app.runtime_path', Path::runtimePath())),
        );
    }

    /**
     * @return TcpConnection|null
     */
    public static function connection(): TcpConnection|null
    {
        return Context::get(TcpConnection::class);
    }

    /**
     * @return Request|null
     */
    public static function request(): Request|null
    {
        return Context::get(Request::class);
    }

    /**
     * @return Server|null
     */
    public static function server(): ?Server
    {
        return static::$server;
    }

    /**
     * Функция для обработки сообщений.
     *
     * @param mixed $connection Соединение TCP.
     * @param mixed $request Запрос.
     * @return null
     * @throws Throwable
     */
    public function onMessage(mixed $connection, mixed $request)
    {
        try {
            // Устанавливаем контекст для соединения и запроса
            Context::set(TcpConnection::class, $connection);
            Context::set(Request::class, $request);

            // Получаем путь из запроса
            $path = $request->path();

            // Создаем ключ из метода запроса и пути
            $key = $request->method() . $path;

            // Если для данного ключа уже есть обратные вызовы
            if (isset(static::$callbacks[$key])) {
                // Получаем обратные вызовы
                [$callback, $request->plugin, $request->app, $request->controller, $request->action, $request->route] = static::$callbacks[$key];

                // Отправляем обратный вызов
                static::send($connection, $callback($request), $request);
                return null;
            }

            $status = 200;

            // Проверяем на небезопасные URI, находим файл или маршрут
            if (
                static::unsafeUri($connection, $path, $request) ||
                static::findFile($connection, $path, $key, $request) ||
                static::findRoute($connection, $path, $key, $request, $status)
            ) {
                return null;
            }

            // Парсим контроллер и действие из пути
            $controllerAndAction = static::parseControllerAction($path);

            // Получаем плагин по пути или из контроллера и действия
            $plugin = $controllerAndAction['plugin'] ?? Plugin::app_by_path($path);

            // Если контроллер и действие не найдены или маршрут по умолчанию отключен
            if (!$controllerAndAction
                || Router::isDefaultRouteDisabled($plugin, $controllerAndAction['app'] ?: '*')
                || Router::isDefaultRouteDisabled($controllerAndAction['controller'])
                || Router::isDefaultRouteDisabled([$controllerAndAction['controller'], $controllerAndAction['action']])
            ) { // Устанавливаем плагин в запросе
                $request->plugin = $plugin;

                // Получаем обратный вызов для отката
                $callback = static::getFallback($plugin, $status);

                // Устанавливаем приложение, контроллер и действие в запросе
                $request->app = $request->controller = $request->action = '';

                // Отправляем обратный вызов
                static::send($connection, $callback($request, $status), $request);
                return null;
            }

            // Получаем приложение, контроллер и действие
            $app = $controllerAndAction['app'];
            $controller = $controllerAndAction['controller'];
            $action = $controllerAndAction['action'];

            // Получаем обратный вызов
            $callback = static::getCallback($plugin, $app, [$controller, $action]);

            // Собираем обратные вызовы
            static::collectCallbacks($key, [$callback, $plugin, $app, $controller, $action, null]);

            // Получаем обратные вызовы
            [$callback, $request->plugin, $request->app, $request->controller, $request->action, $request->route] = static::$callbacks[$key];

            // Отправляем обратный вызов
            static::send($connection, $callback($request), $request);
        } catch (Throwable $e) {
            // Если возникло исключение, отправляем ответ на исключение
            static::send($connection, static::exceptionResponse($e, $request), $request);
        }
        return null;
    }

    /**
     * @param TcpConnection|mixed $connection
     * @param mixed $response
     * @param Request|mixed $request
     * @return void
     * @throws Throwable
     */
    protected static function send(mixed $connection, mixed $response, mixed $request): void
    {
        $keepAlive = $request->header('connection');
        Context::destroy();
        if ((
                $keepAlive === null
                && $request->protocolVersion() === '1.1'
            )
            || $keepAlive === 'keep-alive' || $keepAlive === 'Keep-Alive'
            || (
                is_a($response, Response::class)
                && $response->getHeader('Transfer-Encoding') === 'chunked'
            )
        ) {
            $connection->send($response);
            return;
        }
        $connection->close($response);
    }

    /**
     * @param TcpConnection $connection
     * @param string $path
     * @param $request
     * @return bool
     * @throws Throwable
     */
    protected static function unsafeUri(TcpConnection $connection, string $path, $request): bool
    {
        if (
            !$path ||
            $path[0] !== '/' ||
            str_contains($path, '/../') ||
            str_ends_with($path, '/..') ||
            str_contains($path, "\\") ||
            str_contains($path, "\0")
        ) {
            $callback = static::getFallback(status: 422);
            $request->plugin = $request->app = $request->controller = $request->action = '';
            static::send($connection, $callback($request, 422), $request);
            return true;
        }
        return false;
    }

    /**
     * @param string|null $plugin
     * @param int $status
     * @return Closure
     */
    protected static function getFallback(?string $plugin = '', int $status = 404): Closure
    {
        $fallback = Router::getFallback($plugin ?? '', $status);
        if (!$fallback) {
            Router::fallback(fn() => not_found(), $plugin);
            $fallback = Router::getFallback($plugin ?? '', $status);
        }

        return $fallback;
    }

    /**
     * Функция для поиска файла.
     *
     * @param TcpConnection $connection Соединение TCP.
     * @param string $path Путь.
     * @param string $key Ключ.
     * @param mixed $request Запрос.
     * @return bool Возвращает true, если файл найден, иначе возвращает false.
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    protected static function findFile(TcpConnection $connection, string $path, string $key, mixed $request): bool
    {
        // Если в пути есть процентное кодирование, декодируем его
        if (preg_match('/%[0-9a-f]{2}/i', $path)) {
            $path = urldecode($path);
            if (static::unsafeUri($connection, $path, $request)) {
                return true;
            }
        }

        // Разбиваем путь на части
        $pathExplodes = explode('/', trim($path, '/'));
        $plugin = '';

        // Если путь указывает на плагин
        if (isset($pathExplodes[1]) && $pathExplodes[0] === config('app.plugin_uri', 'app')) {
            $plugin = $pathExplodes[1];
            $publicDir = static::config($plugin, 'app.public_path') ?: Path::basePath(config('app.plugin_alias', 'plugin') . "/$plugin/public");
            $path = substr($path, strlen("/" . config('app.plugin_uri', 'app') . "/$plugin/"));
        } else {
            // Иначе используем общедоступную директорию
            $publicDir = Path::publicPath();
        }

        // Получаем полный путь к файлу
        $file = "$publicDir/$path";

        // Если файл не существует, возвращаем false
        if (!is_file($file)) {
            return false;
        }

        // Если файл является PHP-файлом
        if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
            // Если PHP-файлы не поддерживаются, возвращаем false
            if (!static::config($plugin, 'app.support_php_files', false)) {
                return false;
            }

            // Добавляем обратный вызов для выполнения PHP-файла
            static::collectCallbacks($key, [function () use ($file) {
                return static::execPhpFile($file);
            }, '', '', '', '', null]);

            // Получаем обратные вызовы
            [, $request->plugin, $request->app, $request->controller, $request->action, $request->route] = static::$callbacks[$key];

            // Отправляем обратный вызов
            static::send($connection, static::execPhpFile($file), $request);
            return true;
        }

        // Если статические файлы не поддерживаются, возвращаем false
        if (!static::config($plugin, 'static.enable', false)) {
            return false;
        }

        // Добавляем обратный вызов для отправки файла
        static::collectCallbacks($key, [static::getCallback($plugin, '__static__', function ($request) use ($file, $plugin) {
            clearstatcache(true, $file);
            if (!is_file($file)) {
                $callback = static::getFallback($plugin);
                return $callback($request);
            }
            return (new Response())->file($file);
        }, [], false), '', '', '', '', null]);
        // Получаем обратные вызовы
        [$callback, $request->plugin, $request->app, $request->controller, $request->action, $request->route] = static::$callbacks[$key];
        // Отправляем обратный вызов
        static::send($connection, $callback($request), $request);
        return true;
    }

    /**
     * Конфигурация
     * @param string|null $plugin
     * @param string $key
     * @param null $default
     * @return array|mixed|null
     */
    protected static function config(?string $plugin, string $key, $default = null): mixed
    {
        return Config::get($plugin ? config('app.plugin_alias', 'plugin') . ".$plugin.$key" : $key, $default);
    }

    /**
     * @param string $key
     * @param array $data
     * @return void
     */
    protected static function collectCallbacks(string $key, array $data): void
    {
        static::$callbacks[$key] = $data;
        if (count(static::$callbacks) >= 1024) {
            unset(static::$callbacks[key(static::$callbacks)]);
        }
    }

    /**
     * Выполнить php файл
     * @param string $file
     * @return false|string
     */
    public static function execPhpFile(string $file): false|string
    {
        ob_start();
        // Try to include php file.
        try {
            include $file;
        } catch (Exception $e) {
            echo $e;
        }
        return ob_get_clean();
    }

    /**
     * Функция для получения обратного вызова.
     *
     * @param string|null $plugin Плагин.
     * @param string $app Приложение.
     * @param mixed $call Вызов.
     * @param array|null $args Аргументы.
     * @param bool $withGlobalMiddleware Использовать глобальное промежуточное ПО.
     * @param RouteObject|null $route Маршрут.
     * @return callable|Closure Возвращает обратный вызов.
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public static function getCallback(?string $plugin, string $app, mixed $call, array $args = [], bool $withGlobalMiddleware = true, RouteObject $route = null): callable|Closure
    {
        $middlewares = [];
        $plugin ??= '';

        // Если есть маршрут, получаем промежуточное ПО маршрута
        if ($route) {
            $routeMiddlewares = $route->getMiddleware();
            foreach ($routeMiddlewares as $className) {
                $middlewares[] = [$className, 'process'];
            }
        }
        // Добавляем глобальное промежуточное ПО
        $isController = is_array($call) && is_string($call[0]);
        $middlewares = array_merge($middlewares, Middleware::getMiddleware($plugin, $app, $isController ? $call[0] : '', $withGlobalMiddleware));

        // Создаем экземпляры промежуточного ПО
        $container = static::container($plugin) ?? static::container();
        foreach ($middlewares as $key => $item) {
            $middleware = $item[0];
            if (is_string($middleware)) {
                $middleware = $container->get($middleware);
            } elseif ($middleware instanceof Closure) {
                $middleware = call_user_func($middleware, $container);
            }
            if (!$middleware instanceof MiddlewareInterface) {
                throw new InvalidArgumentException('Неподдерживаемый тип middleware');
            }
            $middlewares[$key][0] = $middleware;
        }

        // Проверяем, нужно ли внедрять зависимости в вызов
        $needInject = static::isNeedInject($call, $args);
        $anonymousArgs = array_values($args);
        if ($isController) {
            $controllerReuse = static::config($plugin, 'app.controller_reuse', true);
            if (!$controllerReuse) {
                if ($needInject) {
                    $call = function ($request) use ($call, $plugin, $args, $container) {
                        $call[0] = $container->make($call[0]);
                        $reflector = static::getReflector($call);
                        $args = array_values(static::resolveMethodDependencies($container, $request, array_merge($request->all(), $args), $reflector));
                        return $call(...$args);
                    };
                    $needInject = false;
                } else {
                    $call = function ($request, ...$anonymousArgs) use ($call, $plugin, $container) {
                        $call[0] = $container->make($call[0]);
                        return $call($request, ...$anonymousArgs);
                    };
                }
            } else {
                $call[0] = $container->get($call[0]);
            }
        }

        // Если нужно внедрить зависимости, внедряем их
        if ($needInject) {
            $call = static::resolveInject($plugin, $call, $args);
        }

        // Если есть промежуточное ПО, создаем цепочку вызовов
        if ($middlewares) {
            $callback = array_reduce($middlewares, function ($carry, $pipe) {
                return function ($request) use ($carry, $pipe) {
                    try {
                        return $pipe($request, $carry);
                    } catch (Throwable $e) {
                        return static::exceptionResponse($e, $request);
                    }
                };
            }, function ($request) use ($call, $anonymousArgs) {
                try {
                    $response = $call($request, ...$anonymousArgs);
                } catch (Throwable $e) {
                    return static::exceptionResponse($e, $request);
                }
                if (!$response instanceof Response) {
                    if (!is_string($response)) {
                        $response = static::stringify($response);
                    }
                    $response = new Response(200, [], $response);
                }
                return $response;
            });
        } else {
            // Если нет промежуточного ПО, создаем обратный вызов
            if (!$anonymousArgs) {
                $callback = $call;
            } else {
                $callback = function ($request) use ($call, $anonymousArgs) {
                    return $call($request, ...$anonymousArgs);
                };
            }
        }
        return $callback;
    }

    /**
     * @param string|null $plugin
     * @return ContainerInterface|array|null
     */
    public static function container(?string $plugin = ''): ContainerInterface|array|null
    {
        return static::config($plugin, 'container');
    }

    /**
     * Check whether inject is required
     * @param $call
     * @param array $args
     * @return bool
     * @throws ReflectionException
     */
    protected static function isNeedInject($call, array &$args): bool
    {
        if (is_array($call) && !method_exists($call[0], $call[1])) {
            return false;
        }

        $reflector = static::getReflector($call);
        $reflectionParameters = $reflector->getParameters();
        if (!$reflectionParameters) {
            return false;
        }
        $firstParameter = current($reflectionParameters);
        unset($reflectionParameters[key($reflectionParameters)]);
        $adaptersList = ['int', 'string', 'bool', 'array', 'object', 'float', 'mixed', 'resource'];
        $keys = [];
        $needInject = false;
        foreach ($reflectionParameters as $parameter) {
            $parameterName = $parameter->name;
            $keys[] = $parameterName;
            if ($parameter->hasType()) {
                $typeName = $parameter->getType()->getName();
                if (!in_array($typeName, $adaptersList)) {
                    $needInject = true;
                    continue;
                }
                if (!array_key_exists($parameterName, $args)) {
                    $needInject = true;
                    continue;
                }
                switch ($typeName) {
                    case 'int':
                    case 'float':
                        if (!is_numeric($args[$parameterName])) {
                            return true;
                        }
                        $args[$parameterName] = $typeName === 'int' ? (int)$args[$parameterName] : (float)$args[$parameterName];
                        break;
                    case 'bool':
                        $args[$parameterName] = (bool)$args[$parameterName];
                        break;
                    case 'array':
                    case 'object':
                        if (!is_array($args[$parameterName])) {
                            return true;
                        }
                        $args[$parameterName] = $typeName === 'array' ? $args[$parameterName] : (object)$args[$parameterName];
                        break;
                    case 'string':
                    case 'mixed':
                    case 'resource':
                        break;
                }
            }
        }
        if (array_keys($args) !== $keys) {
            return true;
        }
        if (!$firstParameter->hasType()) {
            return $firstParameter->getName() !== 'request';
        }

        if (!is_a(static::$requestClass, $firstParameter->getType()->getName(), true)) {
            return true;
        }

        return $needInject;
    }

    /**
     * Get reflector.
     *
     * @param $call
     * @return ReflectionFunction|ReflectionMethod
     * @throws ReflectionException
     */
    protected static function getReflector($call): ReflectionMethod|ReflectionFunction
    {
        if ($call instanceof Closure || is_string($call)) {
            return new ReflectionFunction($call);
        }
        return new ReflectionMethod($call[0], $call[1]);
    }

    /**
     * Функция для получения зависимых параметров.
     *
     * @param ContainerInterface $container
     * @param Request $request Запрос.
     * @param array $inputs
     * @param ReflectionFunctionAbstract $reflector Рефлектор.
     * @return array Возвращает массив с зависимыми параметрами.
     * @throws ReflectionException
     */
    protected static function resolveMethodDependencies(ContainerInterface $container, Request $request, array $inputs, ReflectionFunctionAbstract $reflector): array
    {
        $parameters = [];
        foreach ($reflector->getParameters() as $parameter) {
            $parameterName = $parameter->name;
            $type = $parameter->getType();
            $typeName = $type?->getName();

            if ($typeName && is_a($request, $typeName)) {
                $parameters[$parameterName] = $request;
                continue;
            }

            if (!array_key_exists($parameterName, $inputs)) {
                if (!$parameter->isDefaultValueAvailable()) {
                    if (!$typeName || !class_exists($typeName)) {
                        throw (new MissingInputException())->setData([
                            'parameter' => $parameterName,
                        ]);
                    }
                } else {
                    $parameters[$parameterName] = $parameter->getDefaultValue();
                    continue;
                }
            }
            switch ($typeName) {
                case 'int':
                case 'float':
                    if (!is_numeric($inputs[$parameterName])) {
                        throw (new InputTypeException())->setData([
                            'parameter' => $parameterName,
                            'exceptType' => $typeName,
                            'actualType' => gettype($inputs[$parameterName]),
                        ]);
                    }
                    $parameters[$parameterName] = $typeName === 'float' ? (float)$inputs[$parameterName] : (int)$inputs[$parameterName];
                    break;
                case 'bool':
                    $parameters[$parameterName] = (bool)$inputs[$parameterName];
                    break;
                case 'array':
                case 'object':
                    if (!is_array($inputs[$parameterName])) {
                        throw (new InputTypeException())->setData([
                            'parameter' => $parameterName,
                            'exceptType' => $typeName,
                            'actualType' => gettype($inputs[$parameterName]),
                        ]);
                    }
                    $parameters[$parameterName] = $typeName === 'object' ? (object)$inputs[$parameterName] : $inputs[$parameterName];
                    break;
                case 'string':
                case 'mixed':
                case 'resource':
                case null:
                    $parameters[$parameterName] = $inputs[$parameterName];
                    break;
                default:
                    $subInputs = isset($inputs[$parameterName]) && is_array($inputs[$parameterName]) ? $inputs[$parameterName] : [];
                    if (
                        (class_exists(Model::class) && is_a($typeName, Model::class, true))
                        || (class_exists(ThinkModel::class) && is_a($typeName, ThinkModel::class, true))
                    ) {
                        $parameters[$parameterName] = $container->make($typeName, [
                            'attributes' => $subInputs,
                            'data' => $subInputs
                        ]);
                        break;
                    }
                    if (is_array($subInputs) && $constructor = (new ReflectionClass($typeName))->getConstructor()) {
                        $parameters[$parameterName] = $container->make($typeName, static::resolveMethodDependencies($container, $request, $subInputs, $constructor));
                    } else {
                        $parameters[$parameterName] = $container->make($typeName);
                    }
                    break;
            }
        }

        return $parameters;
    }

    /**
     * @param string|null $plugin
     * @param array|Closure $call
     * @param array|null $args
     * @return Closure
     * @see Dependency injection through reflection information
     */
    protected static function resolveInject(?string $plugin, array|Closure $call, ?array $args): Closure
    {
        return function (Request $request) use ($plugin, $call, $args) {
            $reflector = static::getReflector($call);
            $args = array_values(static::resolveMethodDependencies(static::container($plugin), $request, array_merge($request->all(), $args), $reflector));
            return $call(...$args);
        };
    }

    /**
     * Функция для создания ответа на исключение.
     *
     * @param Throwable $e Исключение.
     * @param mixed $request Запрос.
     * @return Response Возвращает ответ.
     */
    protected static function exceptionResponse(Throwable $e, mixed $request): Response
    {
        // Получаем приложение и плагин из запроса
        $app = $request->app ?: '';
        $plugin = $request->plugin ?: '';

        try {
            // Получаем конфигурацию исключений
            $exceptionConfig = static::config($plugin, 'exception');
            $appExceptionConfig = static::config("", 'exception');

            // Получаем класс обработчика исключений по умолчанию
            if (!isset($exceptionConfig['']) && isset($appExceptionConfig['@'])) {
                $defaultException = $appExceptionConfig['@'] ?? ExceptionHandler::class;
            } else {
                $defaultException = $exceptionConfig[''] ?? ExceptionHandler::class;
            }

            // Получаем класс обработчика исключений для приложения
            $exceptionHandlerClass = $exceptionConfig[$app] ?? $defaultException;

            // Создаем экземпляр обработчика исключений
            /** @var ExceptionHandlerInterface $exceptionHandler */
            $exceptionHandler = (static::container($plugin) ?? static::container(''))
                ->make($exceptionHandlerClass, [
                    'logger' => static::$logger,
                    'debug' => static::config($plugin, 'app.debug')
                ]);
            // Отправляем отчет об исключении
            $exceptionHandler->report($e);
            // Создаем ответ на исключение
            $response = $exceptionHandler->render($request, $e);
            $response->exception($e);
            return $response;
        } catch (Throwable $e) {
            // Если возникло исключение при обработке исключения, создаем ответ с кодом 500
            $response = new Response(500, [], static::config($plugin ?? '', 'app.debug', true) ? (string)$e : $e->getMessage());
            $response->exception($e);
            return $response;
        }
    }

    /**
     * @param mixed $data
     * @return string
     */
    protected static function stringify(mixed $data): string
    {
        $type = gettype($data);
        switch ($type) {
            case 'boolean':
                return $data ? 'true' : 'false';
            case 'NULL':
                return 'NULL';
            case 'array':
                return 'Array';
            case 'object':
                if (!method_exists($data, '__toString')) {
                    return 'Object';
                }
        }
        return (string)$data;

    }

    /**
     * Функция для поиска маршрута.
     *
     * @param TcpConnection $connection Соединение TCP.
     * @param string $path Путь.
     * @param string $key Ключ.
     * @param mixed $request Запрос.
     * @param int $status Статус.
     * @return bool Возвращает true, если маршрут найден, иначе возвращает false.
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    protected static function findRoute(TcpConnection $connection, string $path, string $key, mixed $request, int &$status = 200): bool
    {
        // Получаем информацию о маршруте
        $routeInfo = Router::dispatch($request->method(), $path);
        if ($routeInfo[0] === Dispatcher::NOT_FOUND) {
            $routeInfo = Router::dispatch($request->method(), $request->host(true) . $path);
        }
        // Если маршрут найден
        if ($routeInfo[0] === Dispatcher::FOUND) {
            $status = 200;
            $routeInfo[0] = 'route';
            $callback = $routeInfo[1];
            $app = $controller = $action = '';
            $args = !empty($routeInfo[2]) ? $routeInfo[2] : [];
            $route = clone $routeInfo[3];

            // Установка параметров маршрута, если они есть
            if ($args) {
                $route->setParams($args);
            }
            // Если обратный вызов - это массив
            if (is_array($callback)) {
                $controller = $callback[0];
                $plugin = Plugin::app_by_class($controller);
                $app = static::getAppByController($controller);
                $action = static::getRealMethod($controller, $callback[1]) ?? '';
            } else {
                // Иначе получаем плагин по пути
                $plugin = Plugin::app_by_path($path);
            }
            // Получаем обратный вызов
            $callback = static::getCallback($plugin, $app, $callback, $args, true, $route);
            // Собираем обратные вызовы
            static::collectCallbacks($key, [$callback, $plugin, $app, $controller ?: '', $action, $route]);
            // Получаем обратные вызовы
            [$callback, $request->plugin, $request->app, $request->controller, $request->action, $request->route] = static::$callbacks[$key];
            // Отправляем обратный вызов
            static::send($connection, $callback($request), $request);
            return true;
        }
        $status = $routeInfo[0] === Dispatcher::METHOD_NOT_ALLOWED ? 405 : 404;
        // Если маршрут не найден, возвращаем false
        return false;
    }

    /**
     * @param string $controllerClass
     * @return mixed|string
     */
    protected static function getAppByController(string $controllerClass): mixed
    {
        $controllerClass = trim($controllerClass, '\\');
        $tmp = explode('\\', $controllerClass, 5);
        $pos = $tmp[0] === config('app.plugin_alias', 'plugin') ? 3 : 1;
        if (!isset($tmp[$pos])) {
            return '';
        }
        return strtolower($tmp[$pos]) === 'controller' ? '' : $tmp[$pos];
    }

    /**
     * Получить метод
     * @param string $class
     * @param string $method
     * @return string
     */
    protected static function getRealMethod(string $class, string $method): string
    {
        $method = strtolower($method);
        $methods = get_class_methods($class);
        foreach ($methods as $candidate) {
            if (strtolower($candidate) === $method) {
                return $candidate;
            }
        }
        return $method;
    }

    /**
     * Функция для разбора контроллера и действия из пути.
     *
     * @param string $path Путь.
     * @return array|false Возвращает массив с информацией о контроллере и действии, если они найдены, иначе возвращает false.
     * @throws ReflectionException
     */
    protected static function parseControllerAction(string $path): false|array
    {
        // Удаляем дефисы из пути
        $path = str_replace(['-', '//'], ['', '/'], $path);

        static $cache = [];
        if (isset($cache[$path])) {
            return $cache[$path];
        }

        // Проверяем, является ли путь плагином
        $plugin = Plugin::app_by_path($path);

        // Получаем суффикс контроллера из конфигурации
        $suffix = static::config($plugin, 'app.controller_suffix', '');

        // Получаем префиксы для конфигурации, пути и класса
        $pathPrefix = $plugin ? "/" . config('app.plugin_uri', 'app') . "/$plugin" : '';
        $classPrefix = $plugin ? config('app.plugin_alias', 'plugin') . "\\$plugin" : '';

        // Получаем относительный путь
        $relativePath = trim(substr($path, strlen($pathPrefix)), '/');
        $pathExplode = $relativePath ? explode('/', $relativePath) : [];

        // По умолчанию действие - это 'index'
        $action = 'index';

        // Пытаемся угадать контроллер и действие
        if (!$controllerAction = static::guessControllerAction($pathExplode, $action, $suffix, $classPrefix)) {
            // Если контроллер и действие не найдены и путь состоит из одной части, возвращаем false
            if (count($pathExplode) <= 1) {
                return false;
            }

            $action = end($pathExplode);
            unset($pathExplode[count($pathExplode) - 1]);
            $controllerAction = static::guessControllerAction($pathExplode, $action, $suffix, $classPrefix);
        }

        if ($controllerAction && !isset($path[256])) {
            $cache[$path] = $controllerAction;
            if (count($cache) > 1024) {
                unset($cache[key($cache)]);
            }
        }

        return $controllerAction;
    }


    /**
     * Функция для предположения контроллера и действия.
     *
     * @param array $pathExplode Массив с разделенными частями пути.
     * @param string $action Название действия.
     * @param string $suffix Суффикс.
     * @param string $classPrefix Префикс класса.
     * @return array|false Возвращает массив с информацией о контроллере и действии, если они найдены, иначе возвращает false.
     * @throws ReflectionException
     */
    protected static function guessControllerAction(array $pathExplode, string $action, string $suffix, string $classPrefix): false|array
    {
        // Создаем карту возможных путей к контроллеру
        $map[] = trim("$classPrefix\\app\\controller\\" . implode('\\', $pathExplode), '\\');
        foreach ($pathExplode as $index => $section) {
            $tmp = $pathExplode;
            array_splice($tmp, $index, 1, [$section, 'controller']);
            $map[] = trim("$classPrefix\\" . implode('\\', array_merge(['app'], $tmp)), '\\');
        }
        foreach ($map as $item) {
            $map[] = $item . '\\index';
        }

        // Проверяем каждый возможный путь
        foreach ($map as $controllerClass) {
            // Удаляем xx\xx\controller
            if (str_ends_with($controllerClass, '\\controller')) {
                continue;
            }
            $controllerClass .= $suffix;
            // Если контроллер и действие найдены, возвращаем информацию о них
            if ($controllerAction = static::getControllerAction($controllerClass, $action)) {
                return $controllerAction;
            }
        }

        // Если контроллер или действие не найдены, возвращаем false
        return false;
    }


    /**
     * Функция для получения контроллера и действия.
     *
     * @param string $controllerClass Имя класса контроллера.
     * @param string $action Название действия.
     * @return array|false Возвращает массив с информацией о контроллере и действии, если они найдены, иначе возвращает false.
     * @throws ReflectionException
     */
    protected static function getControllerAction(string $controllerClass, string $action): false|array
    {
        // Отключаем вызов магических методов
        if (str_starts_with($action, '__')) {
            return false;
        }

        // Если класс контроллера и действие найдены, возвращаем информацию о них
        if (($controllerClass = static::getController($controllerClass)) && ($action = static::getAction($controllerClass, $action))) {
            return [
                'plugin' => Plugin::app_by_class($controllerClass),
                'app' => static::getAppByController($controllerClass),
                'controller' => $controllerClass,
                'action' => $action
            ];
        }

        // Если класс контроллера или действие не найдены, возвращаем false
        return false;
    }

    /**
     * Функция для получения контроллера.
     *
     * @param string $controllerClass Имя класса контроллера.
     * @return string|false Возвращает имя класса контроллера, если он найден, иначе возвращает false.
     * @throws ReflectionException
     */
    protected static function getController(string $controllerClass): false|string
    {
        // Если класс контроллера существует, возвращаем его имя
        if (class_exists($controllerClass)) {
            return (new ReflectionClass($controllerClass))->name;
        }

        // Разбиваем полное имя класса на части
        $explodes = explode('\\', strtolower(ltrim($controllerClass, '\\')));
        $basePath = $explodes[0] === config('app.plugin_alias', 'plugin') ? Path::basePath(config('app.plugin_alias', 'plugin')) : app_path();
        unset($explodes[0]);
        $fileName = array_pop($explodes) . '.php';
        $found = true;

        // Ищем соответствующую директорию
        foreach ($explodes as $pathSection) {
            if (!$found) {
                break;
            }
            $dirs = scan_dir($basePath, false);
            $found = false;
            foreach ($dirs as $name) {
                $path = "$basePath/$name";
                if (is_dir($path) && strtolower($name) === $pathSection) {
                    $basePath = $path;
                    $found = true;
                    break;
                }
            }
        }

        // Если директория не найдена, возвращаем false
        if (!$found) {
            return false;
        }

        // Ищем файл контроллера в директории
        foreach (scandir($basePath) ?: [] as $name) {
            if (strtolower($name) === $fileName) {
                require_once "$basePath/$name";
                if (class_exists($controllerClass, false)) {
                    return (new ReflectionClass($controllerClass))->name;
                }
            }
        }

        // Если файл контроллера не найден, возвращаем false
        return false;
    }

    /**
     * Функция для получения действия контроллера.
     *
     * @param string $controllerClass Имя класса контроллера.
     * @param string $action Название действия.
     * @return string|false Возвращает название действия, если оно найдено, иначе возвращает false.
     */
    protected static function getAction(string $controllerClass, string $action): false|string
    {
        // Получаем все методы класса контроллера
        $methods = get_class_methods($controllerClass);
        $lowerAction = strtolower($action);
        $found = false;

        // Проверяем, есть ли метод, соответствующий действию
        foreach ($methods as $candidate) {
            if (strtolower($candidate) === $lowerAction) {
                $action = $candidate;
                $found = true;
                break;
            }
        }

        // Если действие найдено, возвращаем его
        if ($found) {
            return $action;
        }

        // Если действие не является публичным методом, возвращаем false
        if (method_exists($controllerClass, $action)) {
            return false;
        }

        // Если в классе контроллера есть метод __call, возвращаем действие
        if (method_exists($controllerClass, '__call')) {
            return $action;
        }

        // В противном случае возвращаем false
        return false;
    }

    /**
     * @param $server
     * @return void
     * @throws ErrorException
     */
    public function onServerStart($server): void
    {
        static::$server = $server;
        Http::requestClass(static::$requestClass);
        Context::init();
        Autoload::loadAll($server);
    }
}

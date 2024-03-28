<?php

namespace Framework\Routing;

use Error;
use Exception;
use Framework\Foundation\Container;
use Framework\Foundation\View;
use Framework\Http\JsonResponse;
use Framework\Http\RedirectResponse;
use Framework\Http\Request;
use Framework\Support\Url;
use ReflectionException;
use ReflectionMethod;

/**
 * The Router class provides a simple way to define and handle routes in the application.
 *
 * This class provides support for routes based on HTTP method, route naming,
 * and parameter extraction from URLs. It allows for route registration, naming,
 * matching incoming requests to registered routes, and dispatching associated controller actions.
 *
 * @package Framework\Routing
 */
class Router
{
    /**
     * RouteCollection instance containing routes of the application.
     *
     * @var RouteCollection
     */
    private static RouteCollection $routes;

    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    private static function get_instance(): self
    {
        static $instance;

        if (!isset($instance)) {
            $instance = new self(new Container());
        }

        return $instance;
    }

    /**
     * Get the RouteCollection instance containing all registered routes.
     *
     * @return RouteCollection The RouteCollection instance.
     */
    public static function routes(): RouteCollection
    {
        if (!isset(self::$routes)) {
            self::$routes = new RouteCollection();
        }

        return self::$routes;
    }

    /**
     * Get the route path for a named route with parameters applied.
     *
     * @param string $name The name of the route.
     * @param array $parameters Associative array of route parameters.
     * @return string|null The URL for the named route with parameters applied, or null if route not found.
     */
    public function route(string $name, array $parameters = []): ?string
    {
        if (is_null($route = self::routes()->get($name))) {
            return null;
        }

        $route_uri = Url::to($route->uri(), [], true);

        foreach ($parameters as $key => $value) {
            $route_uri = str_replace('{' . $key . '}', $value, $route_uri);
        }

        return $route_uri;
    }

    /**
     * Register a GET route.
     *
     * @param string $uri The URI pattern for the route.
     * @param array $action An array representing the controller and method to be called for this route.
     * @return Router The Router instance.
     */
    public static function get(string $uri, array $action): Router
    {
        return self::get_instance()->add_route('GET', $uri, $action);
    }

    /**
     * Register a POST route.
     *
     * @param string $uri The URI pattern for the route.
     * @param array $action An array representing the controller and method to be called for this route.
     * @return Router The Router instance.
     */
    public static function post(string $uri, array $action): Router
    {
        return self::get_instance()->add_route('POST', $uri, $action);
    }

    /**
     * Add a route to the internal routing.
     *
     * @param string $method The HTTP method for the route.
     * @param string $uri The URI pattern for the route.
     * @param array $action An array representing the controller and method to be called for this route.
     * @return Router The Router instance.
     */
    private static function add_route(string $method, string $uri, array $action): Router
    {
        self::routes()->add(new Route($uri, $method, $action));

        return self::get_instance();
    }

    /**
     * Set a name for a route.
     *
     * @param string $name The name for the route.
     * @return $this The current Router instance.
     *
     * @throws Error If a duplicate route name is detected.
     */
    public function name(string $name): Router
    {
        $routes = self::routes()->all();

        end($routes)->set_name($name);

        return $this;
    }

    /**
     * Set middleware for a route.
     *
     * @param array|string $key
     * @return $this
     */
    public function middleware($key): Router
    {
        $routes = self::routes()->all();
        $route = end($routes);

        if (is_array($key)) {
            $route->set_middleware($key);
        }

        if (is_string($key)) {
            $route->set_middleware([$key]);
        }

        return $this;
    }

    public function find_route(Request $request): ?Route
    {
        return self::routes()->match($request) ?: null;
    }

    /**
     * Resolve the matching route and dispatch the associated controller action with parameters.
     *
     * @param Request $request The current request.
     * @return View|RedirectResponse|JsonResponse|null The result of invoking the controller method, or null if no route matches request.
     */
    public function dispatch(Request $request)
    {
        $route = self::find_route($request);

        if (is_null($route)) {
            return null;
        }

        try {
            [$class, $method] = $route->action();

            $route_uri = Url::to($route->uri(), [], true);

            return $this->resolve_controller([$this->container->get($class), $method], $this->get_parameters($route_uri, $request->request_uri()));
        } catch (Exception $exception) {
            return null;
        }
    }

    /**
     * Resolve the controller method and invoke it with the provided parameters.
     *
     * @param array $action An array containing the controller instance and method.
     * @param array $parameters Associative array of parameters.
     * @return View|RedirectResponse|JsonResponse|null The result of invoking the controller method.
     *
     * @throws ReflectionException If an error occurs during reflection.
     */
    private function resolve_controller(array $action, array $parameters)
    {
        $reflection_method = new ReflectionMethod(...$action);
        $reflection_parameters = [];

        foreach ($reflection_method->getParameters() as $param) {
            $name = $param->getName();
            $type = $param->getType();

            if (!$type) {
                continue;
            }

            if ($type->getName() == Request::class) {
                $reflection_parameters[] = request();
                continue;
            }

            if (is_subclass_of($type->getName(), Request::class)) {
                $reflection_parameters[] = $this->container->get($type->getName());
                continue;
            }

            $reflection_parameters[] = $parameters[$name] ?? null;
        }

        return $reflection_method->invokeArgs($action[0], $reflection_parameters);
    }

    /**
     * Get route parameters from the URL using the corresponding route pattern.
     *
     * @param string $route_url The URL pattern of the route.
     * @param string $url The actual URL.
     * @return array Associative array of route parameters, or empty array if no match.
     */
    private function get_parameters(string $route_url, string $url): ?array
    {
        $pattern = $this->get_pattern($route_url);

        if (preg_match($pattern, $url, $matches)) {
            return array_filter($matches, fn($key) => is_string($key), ARRAY_FILTER_USE_KEY);
        }

        return [];
    }

    /**
     * Get the regex pattern for the route URL.
     *
     * @param string $route_url The URL pattern of the route.
     * @return string The regex pattern for the route URL.
     */
    public function get_pattern(string $route_url): string
    {
        return '#^' . str_replace(['\{', '\}'], ['(?P<', '>[^/]+)'], preg_quote($route_url, '#')) . '$#';
    }
}
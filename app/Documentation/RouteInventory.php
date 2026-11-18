<?php

namespace App\Documentation;

use Closure;
use Illuminate\Support\Facades\Route;
use ReflectionFunction;
use ReflectionMethod;

final class RouteInventory
{
    public function capture(): array
    {
        $rows = [];
        $root = str_replace('\\', '/', base_path()).'/';

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            $uses = $route->getAction('uses');
            $file = null;

            if ($uses instanceof Closure) {
                $file = (new ReflectionFunction($uses))->getFileName();
            } elseif (is_string($uses) && str_contains($uses, '@')) {
                [$class, $method] = explode('@', $uses, 2);

                if (class_exists($class) && method_exists($class, $method)) {
                    $file = (new ReflectionMethod($class, $method))->getFileName();
                }
            }

            $file = $file ? str_replace('\\', '/', $file) : null;
            $source = $file && str_starts_with($file, $root)
                ? substr($file, strlen($root))
                : null;

            foreach ($route->methods() as $method) {
                $domain = $route->getDomain() ?: '*';
                $uri = '/'.ltrim($route->uri(), '/');

                $row = [
                    'id'         => $method.' '.$domain.' '.$uri,
                    'method'     => $method,
                    'domain'     => $domain,
                    'uri'        => $uri,
                    'name'       => $route->getName(),
                    'action'     => $action,
                    'middleware' => $route->gatherMiddleware(),
                    'wheres'     => (object) $route->wheres,
                    'source'     => $source,
                ];

                // Keep declared aliases/groups together with exclusions; resolving
                // groups here would erase the source-level middleware contract.
                if ($excluded = $route->excludedMiddleware()) {
                    $row['excluded_middleware'] = $excluded;
                }

                $rows[] = $row;
            }
        }

        usort($rows, fn (array $left, array $right): int => strcmp($left['id'], $right['id']));

        return $rows;
    }
}

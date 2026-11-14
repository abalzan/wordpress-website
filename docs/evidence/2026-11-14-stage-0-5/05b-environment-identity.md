# Environment identity (read-only, captured before the first suite)

```
NAME                            IMAGE                            COMMAND                  SERVICE     CREATED       STATUS                PORTS
wordpress-website-db-1          mysql:latest                     "docker-entrypoint.s…"   db          3 weeks ago   Up 2 days (healthy)   3306/tcp, 33060/tcp
wordpress-website-wordpress-1   conexao-local/wordpress:latest   "/usr/local/bin/entr…"   wordpress   8 days ago    Up 35 hours           0.0.0.0:8080->80/tcp, [::]:8080->80/tcp
```

```
home	http://localhost:8080
siteurl	http://localhost:8080
```

WP_ENVIRONMENT_TYPE in the running container wp-config.php:

```
0
```

Conclusion: the site is the local Compose install (home = http://localhost:8080),
not conexaobr.ie. The container reports no WP_ENVIRONMENT_TYPE, so
wp_get_environment_type() returns "production"; the bootstrap therefore refuses a
production environment only when the home host is NOT a documented local host,
and always refuses conexaobr.ie / www.conexaobr.ie.

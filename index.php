<?php

require_once __DIR__ . '/vendor/autoload.php';
$app = new Silex\Application();

$app['debug'] = true;

$database = new PDO('sqlite:redirects.sqlite3');
// Set errormode to exceptions
$database->setAttribute(PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION);

// Create new database in memory
$memory_db = new PDO('sqlite::memory:');
// Set errormode to exceptions
$memory_db->setAttribute(PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION);

try {
    $database->exec("CREATE TABLE IF NOT EXISTS redirects (
                    subdomain VARCHAR(255) not NULL PRIMARY KEY,
                    url TEXT not NULL)");

    $database->exec("ALTER TABLE redirects ADD COLUMN views INTEGER DEFAULT 0;");
    $database->exec("ALTER TABLE redirects ADD COLUMN created DATETIME DEFAULT '2015-06-27T11:57:42+00:00' NOT NULL;");
    $database->exec("ALTER TABLE redirects ADD COLUMN lastredirect DATETIME DEFAULT '2015-06-27T11:57:42+00:00' NOT NULL;");

} catch (PDOException $e) {

}


$app->get('/best', function () use ($app, $database) {
    $response = toSubdomain($app, $database);
    if ($response) {
      return $response;
    }
    $statement = $database->prepare("select url,subdomain,views,(strftime('%s')/86400 - strftime('%s',created)/86400) as days from redirects ORDER by views DESC LIMIT 10");
    $statement->execute();
    $rows = $statement->fetchAll();
    $output = "";
    $template = '<h2><a href="http://{{subdomain}}.drshit.ch" target="_blank">{{subdomain}}</a><br /><small>{{days}} days, {{views}} views</small></h2>';
    foreach ($rows as $entry) {
        $output .= str_replace(
            array(
                '{{views}}',
                '{{subdomain}}',
                '{{days}}'
            ),
            array(
                $entry['views'],
                $entry['subdomain'],
                $entry['days']
            ),
            $template);
    }
    return str_replace('{{best}}', $output, file_get_contents('best.html'));
});

$app->get('/fresh', function () use ($app, $database) {
    $response = toSubdomain($app, $database);
    if ($response) {
      return $response;
    }
    $statement = $database->prepare("select url,subdomain,views,created,(strftime('%s')/86400 - strftime('%s',created)/86400) as days from redirects ORDER by ((views-(strftime('%s')/86400 - strftime('%s',created)/86400)*(strftime('%s')/86400 - strftime('%s',created)/86400)))/(LENGTH(subdomain)-LENGTH(REPLACE(subdomain, '.', ''))+1) DESC LIMIT 10");
    $statement->execute();
    $rows = $statement->fetchAll();
    $output = "";
    $template = '<h2><a href="http://{{subdomain}}.drshit.ch" target="_blank">{{subdomain}}</a><br /><small>{{days}} days, {{views}} views</small></h2>';
    foreach ($rows as $entry) {
        $output .= str_replace(
            array(
                '{{views}}',
                '{{subdomain}}',
                '{{days}}'
            ),
            array(
                $entry['views'],
                $entry['subdomain'],
                $entry['days']
            ),
            $template);
    }
    return str_replace('{{fresh}}', $output, file_get_contents('fresh.html'));
});

$library = function () use ($app, $database) {
    $response = toSubdomain($app, $database);
    if ($response) {
      return $response;
    }
    $ending = $app['request']->get('ending');
    $endingLength = 0;
    if ($ending) {
      $endingLength = substr_count($ending, '.') + 1;
      $ending = "%." . $ending;
    } else {
      $ending = "%";
    }
    $statement = $database->prepare("SELECT subdomain FROM redirects WHERE subdomain LIKE :ending ORDER BY (views-(strftime('%s')/86400 - strftime('%s',created)/86400)*(strftime('%s')/86400 - strftime('%s',created)/86400)) DESC");
    $statement->execute(array(':ending' => $ending));
    $rows = $statement->fetchAll();
    $output = "";
    $template = '<h2><a href="http://{{subdomain}}.drshit.ch" target="_blank">{{displaySubdomain}}</a></h2>';
    foreach ($rows as $entry) {
        $subdomainArray = explode(".", $entry['subdomain']);
        $subdomainDisplayLength = count($subdomainArray);
        if ($ending != "%") {
          $subdomainDisplayLength = ($endingLength) * -1;
        }
        $displaySubdomain = implode(".", array_slice($subdomainArray, 0, $subdomainDisplayLength));
        $output .= str_replace(
            array(
              '{{displaySubdomain}}',
              '{{subdomain}}'
            ),
            array(
              $displaySubdomain,
              $entry['subdomain']
            ),
            $template);
    }
    return str_replace('{{links}}', $output, file_get_contents('library.html'));
};

$app->get('/lib/{ending}', $library);
$app->get('/lib', $library);

$app->post('/push', function () {
    shell_exec( 'chmod +x pull' );
    shell_exec( './pull' );
    shell_exec( 'chmod +x pull' );
    return 'It works!';
});

$app->post('/add', function () use ($app, $database) {
    try {
        $subdomain = strtolower($app['request']->get('subdomain'));
        $url = $app['request']->get('url');

        $bannedWords = array(
            'www' => 'Please don\'t do that',
            'drshit' => 'Nope',
        );

        foreach ($bannedWords as $word => $warning) {
            if (preg_match('/' . $word . '/i', $subdomain)) {
                throw new InvalidArgumentException($warning);
            }
        }

        if (!preg_match('/^([a-z0-9]{1,}\.?)+$/i', $subdomain)) {
            throw new InvalidArgumentException('Subdomain is empty or has unallowed characters. Please use only a-z, numerics and dots.');
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $url = 'http://' . $url;
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new InvalidArgumentException('Invalid or empty url');
            }
        }
        insertSubdomain($database, $subdomain, $url);
        return new \Symfony\Component\HttpFoundation\JsonResponse(array('success', "Added redirect successfully <a href='http://$subdomain.drshit.ch'>$subdomain.drshit.ch</a>"));
    } catch (InvalidArgumentException $e) {
        return new \Symfony\Component\HttpFoundation\JsonResponse(array('error', $e->getMessage()));
    } catch (PDOException $e) {
        $statement = $database->prepare("select subdomain,lastredirect from redirects where subdomain = :subdomain");
        $statement->execute(array(':subdomain' => $subdomain));
        $row = $statement->fetch();
        if ((time() - strtotime($row['lastredirect'])) > 7776000) {
            $statement = $database->prepare("DELETE FROM redirects WHERE subdomain = :subdomain");
            $statement->execute(array(':subdomain' => $row['subdomain']));
            insertSubdomain($database, $subdomain, $url);
            return new \Symfony\Component\HttpFoundation\JsonResponse(array('success', 'Created entry for subdomain'));
        } else {
            return new \Symfony\Component\HttpFoundation\JsonResponse(array('error', 'Entry already exists for this subdomain'));

        }

    }

});

$app->get('/{url}', function () use ($app, $database) {
    $response = toSubdomain($app, $database);
    if ($response) {
      return $response;
    }
    $givenUrl = strtolower($app['request']->get('url'));
    return str_replace("{{url}}", $givenUrl, file_get_contents('template.html'));
})->value('url', '')->assert("url", ".*");

function toSubdomain($app, $database) {
  $url = parse_url($app['request']->server->get('HTTP_HOST'));
  if (isset($url['host']) || isset($url['path'])) {
      $host = isset($url['host']) ? $url['host'] : $url['path'];
      preg_match('/(?P<subdomain>[a-z0-9\.]+)\.(drshit.(dev|local|ch)|localhost)/', $host, $matches);
      if (isset($matches['subdomain'])) {
          $subdomain = $matches['subdomain'];
          if ($subdomain == 'www' || preg_match('/drshit/i', $subdomain)) {
              return file_get_contents('template.html');
          }
          $statement = $database->prepare("select url from redirects where subdomain = :subdomain");
          $statement->execute(array(':subdomain' => $subdomain));
          $row = $statement->fetch();
          if (!isset($row['url'])) {
              return new \Symfony\Component\HttpFoundation\RedirectResponse('http://wikipedia.org/wiki/' . $subdomain);
          }
          $statement = $database->prepare(" UPDATE redirects SET views = views + 1 where subdomain = :subdomain");
          $statement->execute(array(':subdomain' => $subdomain));
          $statement = $database->prepare(" UPDATE redirects SET lastredirect = :currentdatetime where subdomain = :subdomain");
          $statement->execute(array(':subdomain' => $subdomain, ':currentdatetime' => date('c')));

          return new \Symfony\Component\HttpFoundation\RedirectResponse($row['url']);
      }
  }
}

function insertSubdomain($database, $subdomain, $url) {
    $insert = "INSERT INTO redirects (subdomain, url, created, lastredirect)
            VALUES (:subdomain, :url, :created, :lastredirect)";
    $stmt = $database->prepare($insert);
    $stmt->bindParam(':subdomain', $subdomain);
    $stmt->bindParam(':url', $url);
    $stmt->bindParam(':created', date('c'));
    $stmt->bindParam(':lastredirect', date('c'));
    $stmt->execute();
}

$app->run();

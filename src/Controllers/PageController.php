<?php
namespace KirchenImWeb\Controllers;

use KirchenImWeb\Helpers\Configuration;
use KirchenImWeb\Helpers\Database;
use KirchenImWeb\Helpers\Exporter;
use KirchenImWeb\Helpers\ParameterChecker;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Views\Twig;
use Slim\Views\TwigExtension;
use Twig_Extensions_Extension_I18n;

/**
 * Class PageController
 *
 * @package KirchenImWeb\Controllers
 */
class PageController {
    private $twig;

    public function __construct(ContainerInterface $container) {
        // Configure Twig for templates and translations.
        $this->twig = new Twig(__DIR__ . '/../templates', [
            'cache' => (defined('DEBUG') && DEBUG) ? false : __DIR__ . '/../../cache'
        ]);
        $this->twig->addExtension(new Twig_Extensions_Extension_I18n());

        // Instantiate and add Slim specific extension
        $basePath = rtrim(str_ireplace('index.php', '', $container['request']->getUri()->getBasePath()), '/');
        $this->twig->addExtension(new TwigExtension($container['router'], $basePath));

        // Pass global variables to the view.
        $this->twig->offsetSet('currentPath', $container['request']->getUri()->getPath());
        $this->twig->offsetSet('config', Configuration::getInstance());

        // Init textdomain and set default language.
        $domain = "kirchen-im-web25";
        bindtextdomain($domain, 'lang');
        bind_textdomain_codeset($domain, 'UTF-8');
        textdomain($domain);
    }

    public function index(Request $request, Response $response, array $args) {
        return $this->twig->render($response, 'index.html.twig', [
            'title' => 'Das Projekt kirchen-im-web.de',
            'description' => 'Viele Kirchengemeinden nutzen mittlerweile Social-Media-Auftritte.'
        ]);
    }

    public function map(Request $request, Response $response, array $args) {
        global $websites;
        return $this->twig->render($response, 'map.html.twig', [
            'title' => 'Karte',
            'headline' => 'Karte kirchlicher Web- und Social-Media-Auftritte',
            'websites' => $websites
        ]);
    }

    public function search(Request $request, Response $response, array $args) {
        $pc = ParameterChecker::getInstance();
        $filters = $pc->extractFilterParameters($request);
        $websites = $pc->extractFilterWebsites($request);
        return $this->twig->render($response, 'table.html.twig', [
            'title' => 'Suche',
            'headline' => 'Kirchliche Web- und Social-Media-Auftritte',
            'description' => 'Viele Kirchengemeinden nutzen mittlerweile Social-Media-Auftritte.',
            'compare' => false,
            'filters' => $filters,
            'websites' => $websites,
            'sort' => $pc->extractSort($request, $websites),
            'entries' => Database::getInstance()->getFilteredEntries($filters, $websites)
        ]);
    }

    public function comparison(Request $request, Response $response, array $args) {
        $pc = ParameterChecker::getInstance();
        $filters = $pc->extractFilterParameters($request);
        $websites = Configuration::getInstance()->networksToCompare;
        $this->twig->render($response, 'table.html.twig', [
            'title' => 'Vergleich kirchlicher Social-Media-Auftritte',
            'headline' => 'Vergleich kirchlicher Social-Media-Auftritte',
            'description' => 'kirchen-im-web.de vergleicht kirchliche Social-Media-Auftritte anhand ihrer Follower-Zahlen.',
            'compare' => true,
            'filters' => $filters,
            'websites' => $websites,
            'sort' => $pc->extractSort($request, $websites),
            'entries' => Database::getInstance()->getFilteredEntries($filters, $websites, true)
        ]);
    }

    public function add(Request $request, Response $response, array $args) {
        $data = ParameterChecker::getInstance()->parseAddFormPreSelectionParameters($request);
        return $this->addResponse($response, $data);
    }

    public function addForm(Request $request, Response $response, array $args) {
        $check = ParameterChecker::getInstance()->parseAddFormParameters($request);
        $added = [];

        if ($check['correct']) {
            $added = Database::getInstance()->addChurch($check['data']);
            Exporter::getInstance()->export();
            $check['data'] = [];
        }
        return $this->addResponse($response, $check['data'], $added, $check['messages']);
    }

    private function addResponse(Response $response, array $data, array $added = [], array $messages = []) {
        return $this->twig->render($response, 'add.html.twig', [
            'title' => 'Gemeinde eintragen',
            'description' => 'Hier können Sie Ihre Gemeinde zu kirchen-im-web.de hinzufügen.',
            'data' => $data,
            'added' => $added,
            'messages' => $messages,
            'parents' => Database::getInstance()->getParentEntries()
        ]);
    }

    public function stats(Request $request, Response $response, array $args) {
        $db = Database::getInstance();
        return $this->twig->render($response, 'stats.html.twig', [
            'title' => 'Statistik',
            'total' => $db->getTotalCount(),
            'statsByCountry' => $db->getStatsByCountry(),
            'statsByDenomination' => $db->getStatsByDenomination(),
            'statsByType' => $db->getStatsByType(),
            'statsByWebsite' => $db->getStatsByWebsite(),
            'statsHTTPS' => $db->getStatsHTTPS()
        ]);
    }

    public function links(Request $request, Response $response, array $args) {
        $this->twig->render($response, 'links.html.twig', [
            'title' => 'Linkliste Webauftritte und Social-Media-Auftritte gestalten',
            'headline' => 'Tipps und Tricks'
        ]);
    }

    public function details(Request $request, Response $response, array $args) {
        $entry = Database::getInstance()->getEntry($args['id'], true);
        if (!$entry) {
            return $this->error($request, $response, $args);
        }

        $this->twig->render($response, 'details.html.twig', [
            'entry' => $entry
        ]);
    }

    public function legal(Request $request, Response $response, array $args) {
        return $this->twig->render($response, 'legal.html.twig', [
            'title' => 'Impressum'
        ]);
    }

    public function data(Request $request, Response $response, array $args) {
        return $this->twig->render($response, 'data.html.twig', [
            'title' => 'Offene Daten',
            'entries' => Database::getInstance()->getRecentlyAddedEntries(),
        ]);
    }

    public function error(Request $request, Response $response, array $args) {
        if (substr($request->getRequestTarget(), 0, 3) == '/en') {
            $this->setLanguage('en_US', $request);
        } else {
            $this->setLanguage('de_DE', $request);
        }

        return $this->twig->render($response, 'default.html.twig', [
            'title' => 'Kirchliche Web- und Social-Media-Auftritte',
            'headline' => 'Seite nicht gefunden',
            'text' => 'Leider konnte die gewünschte Seite nicht gefunden werden. Möglicherweise wurde dieser Eintrag gelöscht.'
        ])->withStatus(404);
    }

    public function sitemap(Request $request, Response $response, array $args) {
        return $this->twig->render($response, 'sitemap.xml.twig', [
            'ids' => Database::getInstance()->getIds()
        ])->withHeader('Content-Type', 'text/xml; charset=UTF-8');
    }

    public function setLanguage($language, Request $request) {
        putenv(sprintf('LC_ALL=%s', $language));
        setlocale(LC_ALL, $language);
        $this->twig->offsetSet('language', $language);
        $languageSlug = substr($language, 0, 2);
        $this->twig->offsetSet('languageSlug', $languageSlug);

        // Set Menu
        $route = $request->getAttribute('route');
        $routeWithoutLanguagePrefix = $route ? substr($route->getName(), 3) : 'home';

        $headerMenuItems = [
            [
                'path' => $languageSlug . '-home',
                'text' => 'Über das Projekt'
            ], [
                'path' => $languageSlug . '-map',
                'text' => 'Karte'
            ], [
                'path' => $languageSlug . '-search',
                'text' => 'Suche'
            ], [
                'path' => $languageSlug . '-comparison',
                'text' => 'Social-Media-Vergleich'
            ], [
                'path' => $languageSlug . '-add',
                'text' => 'Gemeinde eintragen'
            ], [
                'path' => $languageSlug . '-stats',
                'text' => 'Statistik'
            ]
        ];

        if ($language === 'de_DE') {
            $headerMenuItems = array_merge($headerMenuItems, [
                [
                    'path' => 'de-links',
                    'text' => 'Tipps und Tricks'
                ]
            ]);

            if ($routeWithoutLanguagePrefix !== 'links') {
                $headerMenuItems = array_merge($headerMenuItems, [
                    [
                        'class' => 'lang_en',
                        'path' => 'en-' . $routeWithoutLanguagePrefix,
                        'text' => 'English'
                    ]
                ]);
            }

        } elseif ($language === 'en_US') {
            $headerMenuItems = array_merge($headerMenuItems, [
                [
                    'class' => 'lang_de',
                    'path' => 'de-' . $routeWithoutLanguagePrefix,
                    'text' => 'Deutsch'
                ]
            ]);
        }

        $footerMenuItems = [
            [
                'path' => $languageSlug . '-legal',
                'text' => 'Impressum'
            ], [
                'path' => $languageSlug . '-data',
                'text' => 'Offene Daten'
            ]
        ];

        $this->twig->offsetSet('headerMenu', $headerMenuItems);
        $this->twig->offsetSet('footerMenu', $footerMenuItems);
    }
}

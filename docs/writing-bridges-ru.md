---

## 🇷🇺 Русская версия: `docs/writing-bridges.ru.md`

```markdown
# Написание мостов для форка RSS-Bridge от LordArrin

Это руководство посвящено написанию мостов именно для этого форка. В нём рассматриваются современный PHP 8.5, нативный API `\Dom\HTMLDocument`, пространства имён PSR-4 и библиотека утилит `quirks/` — всё то, что значительно отличается от оригинального проекта RSS-Bridge.

## Ключевые отличия от оригинала

| Возможность | Оригинальный RSS-Bridge | Этот форк |
|---|---|---|
| Версия PHP | 7.4+ | 8.5+ |
| Строгая типизация | Опциональна | Обязательна (`declare(strict_types=1)`) |
| HTML-парсер | `simple_html_dom` (встроен) | `\Dom\HTMLDocument` (нативный PHP, PHP 8.4+) |
| Новые мосты | `bridges/` (глобальное пространство имён) | `bridges-v2/` с пространством имён `RSSBridge\Bridges` |
| HTML-утилиты | В каждом мосте отдельно | Централизованно в папке `quirks/` |
| HTTP-клиент | Собственный, опциональный curl-impersonate | curl-impersonate встроен в образ Alpine |
| Markdown | Встроенный Parsedown 1.7.4 | `erusev/parsedown` 1.8+ через Composer |
| URL joining | Встроен | `busybee/urljoin` через Composer |

## Структура директорий

- **`bridges/`** — легаси-мосты (сохранены для совместимости, глобальное пространство имён)
- **`bridges-v2/`** — новые мосты (PSR-4, с пространствами имён, со строгой типизацией)
- **`quirks/`** — утилиты для мостов (HTML-помощники, работа с DOM, обработка медиа)
- **`lib/`** — классы ядра (частично мигрированы в пространство имён `RSSBridge`)

**Всегда размещайте новые мосты в `bridges-v2/`.** Легаси-папка `bridges/` сохранена только чтобы не ломать совместимость с оригиналом.

## Минимальный шаблон моста

```php
<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class ExampleBridge extends BridgeAbstract
{
    const NAME = 'Example';
    const URI = 'https://example.com/';
    const DESCRIPTION = 'Returns example content';
    const MAINTAINER = 'yourname';
    const CACHE_TIMEOUT = 3600;

    public function collectData(): void
    {
        $html = $this->fetchHtml(self::URI);
        $title = $html->querySelector('h1')?->textContent ?? 'Untitled';

        $this->items[] = [
            'title' => $title,
            'uri' => self::URI,
            'content' => 'Hello, world',
            'timestamp' => time(),
            'uid' => 'example-1',
        ];
    }
}
```

## Обязательные константы

Все мосты должны определять как минимум эти константы:

```php
const NAME = 'Display Name';           // Человекочитаемое имя
const URI = 'https://example.com/';    // Канонический URL
const DESCRIPTION = 'What it does';    // Краткое описание
const MAINTAINER = 'yourname';         // Имя пользователя GitHub
const CACHE_TIMEOUT = 3600;            // Длительность кэша в секундах
```

## Опционально: Параметры

Определите настраиваемые пользователем входы:

```php
const PARAMETERS = [
    '' => [  // Контекст по умолчанию
        'username' => [
            'name' => 'Username',
            'type' => 'text',           // text, list, checkbox, number
            'required' => true,
            'exampleValue' => 'alice',
            'title' => 'Введите имя пользователя',
        ],
        'limit' => [
            'name' => 'Лимит элементов',
            'type' => 'number',
            'defaultValue' => 10,
        ],
        'include_replies' => [
            'name' => 'Включить ответы',
            'type' => 'checkbox',
            'defaultValue' => false,
        ],
    ],
    // Дополнительные контексты (опционально):
    'By tag' => [
        'tag' => [
            'name' => 'Название тега',
            'type' => 'text',
            'required' => true,
        ],
    ],
];
```

Доступ к параметрам через `$this->getInput('username')`.

## Опционально: Конфигурация

Для секретов и чувствительных значений (API-токены, куки) используйте `CONFIGURATION`:

```php
const CONFIGURATION = [
    'api_token' => ['required' => true],
    'session_cookie' => ['required' => false],
];
```

Доступ через `$this->getOption('api_token')`. Они задаются в `config.ini.php`:

```ini
[ExampleBridge]
api_token = "your-token-here"
```

## Утилиты Quirks

Папка `quirks/` содержит проверенные временем утилиты для распространённых задач мостов. **Используйте их вместо написания собственных реализаций.**

Все функции quirks доступны глобально (загружаются через `files` автозагрузчик Composer) и имеют включённый `declare(strict_types=1)`.

### Помощники генерации HTML (`quirks/html.php`)

Безопасная генерация HTML-фрагментов для содержимого элементов фида:

```php
// Экранирование пользовательского ввода (предотвращает XSS)
$safe = e($userInput);  // htmlspecialchars с ENT_QUOTES | ENT_SUBSTITUTE

// Пометить доверенный HTML как безопасный (семантическая пустая операция)
$trusted = raw($preRenderedHtml);

// Обрезка длинных строк
$short = truncate($longText, 200, '...');

// Генерация HTML-тегов с валидацией
$input = html_input(['type' => 'text', 'name' => 'q', 'value' => 'search']);
$option = html_option('United States', 'us', true);
$div = html_tag('div', 'Hello', ['class' => 'greeting', 'id' => 'hello']);
```

`html_tag()` проверяет имена атрибутов по белому списку, предотвращая случайную инъекцию обработчиков событий вроде `onclick`.

### Работа с DOM (`quirks/dom.php`)

Обработка HTML-контента для элементов фида:

```php
// Очистка HTML: удаление скриптов, iframe, сохранение только безопасных атрибутов
$clean = sanitize($html);
$clean = sanitize($html, ['script', 'iframe'], ['href', 'src'], ['p', 'strong']);

// Преобразование относительных URL в абсолютные (обрабатывает img, a, script, link, video, audio, iframe)
$absolute = defaultLinkTo($html, 'https://example.com/');

// Преобразование лениво-загружаемых изображений в статические (data-src, data-srcset, data-lazy-src)
$static = convertLazyLoading($html);

// Замена CSS background-image на теги <img>
$withImgs = backgroundToImg($html);

// "Поломка" опасных тегов (script, iframe, link) с сохранением их видимости
$broken = break_annoying_html_tags($html);
```

**Рекомендуемый паттерн** для обработки HTML статьи:

```php
$html = getContents($articleUrl);
$html = defaultLinkTo($html, $articleUrl);  // Исправление относительных URL
$html = convertLazyLoading($html);          // Обработка лениво-загружаемых изображений
$html = sanitize($html);                    // Удаление скриптов/iframe
$item['content'] = $html;
```

### Извлечение из строк (`quirks/extract.php`)

Извлечение данных из HTML-строк или встроенного JavaScript:

```php
// Извлечение текста между разделителями
$data = extractFromDelimiters($html, 'window.data = ', ';');

// Удаление секций HTML
$clean = stripWithDelimiters($html, '<script>', '</script>');
$clean = stripRecursiveHTMLSection($html, 'div', '<div class="ads">');
```

### Парсинг srcset (`quirks/srcset.php`)

Работа с адаптивными изображениями:

```php
// Парсинг атрибута srcset
$entries = parseSrcset('image-320w.jpg 320w, image-1024w.jpg 1024w');
// => ['320w' => 'image-320w.jpg', '1024w' => 'image-1024w.jpg']

// Получение URL наибольшего изображения
$largest = parseSrcsetLargestImageUrl($srcset);
```

### Обработка медиа (`quirks/media.php`)

```php
// Преобразование Markdown в HTML
$html = markdownToHtml($markdown, ['breaksEnabled' => true]);

// Генерация встраиваемого YouTube или превью
$embed = handleYoutube('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
// Возвращает <iframe> или <picture> с WebP/JPEG srcset в зависимости от конфигурации
```

### Извлечение SEO-метаданных (`quirks/seo.php`)

Автоматическое извлечение метаданных Open Graph, Twitter Cards и JSON-LD:

```php
$metadata = html_find_seo_metadata($html);
// Возвращает массив с ключами: uri, title, content, timestamp, enclosures, author

// Использование в мосту:
$item = [
    'title' => $metadata['title'] ?? $fallbackTitle,
    'uri' => $metadata['uri'] ?? $articleUrl,
    'content' => $articleContent,
    'timestamp' => $metadata['timestamp'] ?? time(),
    'author' => $metadata['author'] ?? null,
    'enclosures' => $metadata['enclosures'] ?? [],
];
```

### Рендеринг шаблонов (`quirks/template.php`)

Используется внутри actions RSS-Bridge для рендеринга HTML-страниц. Редко нужен в мостах, но доступен, если требуется генерировать сложный HTML:

```php
$html = render('my-template.html.php', ['items' => $items]);
```

## Парсинг HTML через нативный DOM

**Никогда не используйте функции `simple_html_dom` напрямую в новых мостах** (`str_get_html()`, `->find()`, `->plaintext`, `->innertext`). Вместо этого используйте нативный PHP 8.4+ API `\Dom\HTMLDocument` для новой логики парсинга.

**Исключение:** функции в `quirks/dom.php` (вроде `sanitize()`, `defaultLinkTo()`) используют `simple_html_dom` внутри — это нормально. Относитесь к ним как к утилитам "чёрного ящика".

### Получение HTML

```php
private function fetchHtml(string $url): \Dom\HTMLDocument
{
    $html = getContents($url);
    if (empty($html)) {
        throwServerException("Failed to fetch {$url}");
    }

    libxml_use_internal_errors(true);
    $dom = \Dom\HTMLDocument::createFromString($html);
    libxml_use_internal_errors(false);

    return $dom;
}
```

`libxml_use_internal_errors` подавляет предупреждения о некорректном HTML, что крайне распространено на реальных сайтах.

### Селекторы

```php
// Один элемент (возвращает \Dom\Element или null)
$title = $dom->querySelector('h1.title');
$author = $dom->querySelector('article .author a');

// Множество элементов (возвращает \Dom\NodeList — итерируемый)
foreach ($dom->querySelectorAll('article.post') as $post) {
    // ...
}

// Преобразование в массив, если нужны функции array_*
$items = iterator_to_array($dom->querySelectorAll('li'));
```

### Доступ к данным

| Свойство | Тип | Пример |
|---|---|---|
| `$node->textContent` | `string` | Простой текст, удаляет HTML |
| `$node->innerHTML` | `string` | Внутренний HTML |
| `$node->outerHTML` | `string` | Элемент + внутренний HTML |
| `$node->getAttribute('href')` | `?string` | Значение атрибута |
| `$node->tagName` | `string` | Имя тега (в нижнем регистре) |
| `$node->parentElement` | `?\Dom\Element` | Родительский элемент |
| `$node->parentNode` | `?\Dom\Node` | Родительский узел (может быть документом) |
| `$node->childNodes` | `\Dom\NodeList` | Дочерние узлы |
| `$dom->saveHTML()` | `string` | Полный HTML документа |

### Обход родителей

```php
// Подъём по дереву
$current = $node;
while ($current !== null && $current instanceof \Dom\Element) {
    if ($current->tagName === 'article') {
        return $current;
    }
    $current = $current->parentElement;  // используйте parentElement, а не parentNode
}
```

## Работа с JSON API

Для сайтов на основе JSON пропустите HTML полностью:

```php
public function collectData(): void
{
    $url = 'https://api.example.com/posts';
    $json = getContents($url, ['Accept: application/json']);
    $data = json_decode($json, true);

    if (!is_array($data)) {
        throwServerException('Invalid JSON response');
    }

    foreach ($data['items'] ?? [] as $item) {
        $this->items[] = [
            'title' => $item['title'] ?? 'Untitled',
            'uri' => $item['url'] ?? self::URI,
            'content' => $item['body'] ?? '',
            'timestamp' => isset($item['date']) ? strtotime($item['date']) : time(),
            'uid' => (string)($item['id'] ?? uniqid()),
        ];
    }
}
```

## Обработка ошибок

Используйте стандартные исключения — никогда не бросайте сырой `\Exception`:

```php
// Пользователь сделал плохой запрос
throwClientException('Invalid user ID');

// Проблема на стороне сервера или upstream
throwServerException('Failed to fetch data');

// Достигнут лимит запросов
throwRateLimitException();

// Ошибка HTTP с кодом
throw new HttpException('Not Found', 404);
```

Все исключения перехватываются на верхнем уровне и рендерятся в выходной фид.

## Структура элемента фида

Каждый элемент должен содержать эти поля:

```php
[
    'title'     => 'Item Title',        // Обязательно
    'uri'       => 'https://...',       // Обязательно
    'content'   => '<p>HTML body</p>',  // Обязательно
    'timestamp' => 1700000000,          // Unix timestamp (int), опционально
    'author'    => 'Alice',             // Опционально
    'uid'       => 'unique-id',         // Глобально уникальный, опционально
    'categories' => ['news', 'tech'],   // Опционально
    'enclosures' => ['https://img.jpg'], // Опциональные медиа
]
```

## Кэширование

Кэш моста доступен через `$this->cache`:

```php
$cacheKey = 'example_' . md5($url);
$cached = $this->cache->get($cacheKey);

if ($cached !== null) {
    return $cached;
}

// ... получение данных ...

$this->cache->set($cacheKey, $data, 3600);  // TTL в секундах
```

Обратите внимание, что `getContents()` и `getSimpleHTMLDOMCached()` уже кэшируют HTTP-ответы внутри — используйте кэш моста только для распарсенных/обработанных данных.

## Прокси / Обход Cloudflare

Для сайтов, защищённых Cloudflare, используйте систему прокси:

```php
$html = getProtectedContents($url, 'flaresolverr', [
    'cookies' => [
        ['name' => 'session', 'value' => 'abc123', 'domain' => 'example.com'],
    ],
    'cache_ttl' => 900,
]);
```

Затем парсите через `\Dom\HTMLDocument::createFromString($html)`.

Профиль прокси настраивается в `config.ini.php`:

```ini
[proxy_profile_flaresolverr]
type = "FlareSolverr"
url = "http://localhost:8191"
```

## Относительные URL

**Предпочтительно: используйте `defaultLinkTo()` из quirks** для обработки всех относительных URL в HTML-фрагменте за раз:

```php
$html = getContents($articleUrl);
$html = defaultLinkTo($html, $articleUrl);  // Исправляет img src, a href, video src и т.д.
```

**Ручной подход:** используйте `urljoin()` для отдельных URL:

```php
use function urljoin;

$absolute = urljoin('https://example.com/posts/', '/img/photo.jpg');
// => 'https://example.com/img/photo.jpg'
```

## Markdown в содержимом

Рендеринг Markdown в HTML с помощью Parsedown:

```php
$parsedown = new \Parsedown();
$html = $parsedown->text($markdown);
```

Или используйте quirks-обёртку с опциями:

```php
$html = markdownToHtml($markdown, [
    'breaksEnabled' => true,
    'markupEscaped' => true,
]);
```

## Классы ядра с пространствами имён

Несколько классов ядра были мигрированы в пространство имён `RSSBridge`. Когда они нужны в вашем мосту, импортируйте их явно:

```php
use RSSBridge\Configuration;
use RSSBridge\FeedItem;
use RSSBridge\FeedParser;

// Доступ к конфигурации
$apiKey = Configuration::getConfig('MyBridge', 'api_key');

// Парсинг существующего RSS/Atom фида
$parser = new FeedParser();
$feed = $parser->parseFeed($xmlString);
```

**Примечание:** `BridgeAbstract` всё ещё в глобальном пространстве имён (для совместимости с легаси-мостами), поэтому `use BridgeAbstract;` работает без префикса пространства имён. Это изменится в будущем, когда все легаси-мосты будут мигрированы.

## Запуск проверок качества

Из корня репозитория:

```bash
# Линтинг вашего моста
vendor/bin/phpcs --standard=phpcs.xml bridges-v2/YourBridge.php

# Автоисправление проблем стиля
vendor/bin/phpcbf --standard=phpcs.xml bridges-v2/YourBridge.php

# Статический анализ
vendor/bin/phpstan analyse bridges-v2/YourBridge.php --level=5

# Запуск тестов
vendor/bin/phpunit
```

Изнутри Docker-контейнера:

```bash
docker exec rss-bridge vendor/bin/phpcs --standard=phpcs.xml bridges-v2/YourBridge.php
```

## Тестирование вашего моста

1. Перезапустите контейнер после добавления нового моста:
   ```bash
   docker restart rss-bridge
   ```
2. Откройте `http://localhost:3000/` в браузере.
3. Найдите ваш мост в списке, заполните параметры, нажмите "Show result".
4. Проверьте полученный фид на корректность.

## Чеклист перед коммитом

- [ ] Файл находится в `bridges-v2/` (не в `bridges/`)
- [ ] `declare(strict_types=1)` в начале
- [ ] Объявлен `namespace RSSBridge\Bridges;`
- [ ] Класс объявлен как `final` и расширяет `BridgeAbstract`
- [ ] Определены все 5 обязательных констант (NAME, URI, DESCRIPTION, MAINTAINER, CACHE_TIMEOUT)
- [ ] Нет прямых вызовов `simple_html_dom` (`str_get_html()`, `->find()`, `->plaintext`)
- [ ] Используется `\Dom\HTMLDocument` с `querySelector` / `querySelectorAll` для нового парсинга
- [ ] Используются quirks-утилиты (`defaultLinkTo()`, `convertLazyLoading()`, `sanitize()`) для обработки HTML
- [ ] `libxml_use_internal_errors(true)` оборачивает парсинг HTML
- [ ] Null-safe оператор `?->` используется для опциональных элементов
- [ ] Относительные URL обрабатываются через `defaultLinkTo()` или `urljoin()`
- [ ] `phpcs` проходит без предупреждений
- [ ] Мост протестирован на реальных данных в Docker

## Полный эталонный пример

```php
<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class BlogBridge extends BridgeAbstract
{
    const NAME = 'Example Blog';
    const URI = 'https://blog.example.com/';
    const DESCRIPTION = 'Latest posts from Example Blog';
    const MAINTAINER = 'yourname';
    const CACHE_TIMEOUT = 1800;

    const PARAMETERS = [
        '' => [
            'category' => [
                'name' => 'Категория',
                'type' => 'list',
                'values' => [
                    'Все' => '',
                    'Технологии' => 'tech',
                    'Дизайн' => 'design',
                ],
                'defaultValue' => '',
            ],
            'limit' => [
                'name' => 'Постов на фид',
                'type' => 'number',
                'defaultValue' => 20,
            ],
        ],
    ];

    public function collectData(): void
    {
        $category = (string)$this->getInput('category');
        $limit = (int)($this->getInput('limit') ?: 20);

        $url = $category !== '' ? self::URI . "category/{$category}/" : self::URI;
        $dom = $this->fetchHtml($url);

        $posts = $dom->querySelectorAll('article.post');
        $count = 0;

        foreach ($posts as $post) {
            if ($count >= $limit) {
                break;
            }

            $link = $post->querySelector('a.post-title');
            if ($link === null) {
                continue;
            }

            $uri = $link->getAttribute('href');
            $title = trim($link->textContent);

            // Получение полной статьи
            $articleHtml = getContents($uri);
            
            // Обработка HTML статьи через утилиты quirks
            $articleHtml = defaultLinkTo($articleHtml, $uri);
            $articleHtml = convertLazyLoading($articleHtml);
            $articleHtml = sanitize($articleHtml);

            // Извлечение метаданных для запасного варианта
            $metadata = html_find_seo_metadata($articleHtml);

            $dateStr = $post->querySelector('time')?->getAttribute('datetime');
            $timestamp = $dateStr !== null ? strtotime($dateStr) : ($metadata['timestamp'] ?? time());
            $author = $post->querySelector('.author-name')?->textContent ?? ($metadata['author'] ?? null);

            $this->items[] = [
                'title' => $title,
                'uri' => $uri,
                'content' => $articleHtml,
                'timestamp' => $timestamp !== false ? $timestamp : time(),
                'author' => $author !== null ? trim($author) : null,
                'uid' => $uri,
                'enclosures' => $metadata['enclosures'] ?? [],
            ];

            $count++;
        }

        if ($this->items === []) {
            throwServerException('Посты не найдены. Возможно, сайт изменил свою структуру.');
        }
    }

    private function fetchHtml(string $url): \Dom\HTMLDocument
    {
        $html = getContents($url);
        if (empty($html)) {
            throwServerException("Пустой ответ от {$url}");
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        return $dom;
    }
}
```

## Вопросы?

Открывайте issue на [github.com/LordArrin/rss-bridge](https://github.com/LordArrin/rss-bridge) — сообщество радо помочь новым авторам мостов.
```

---
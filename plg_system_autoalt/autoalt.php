<?php

/**
 * @copyright
 * @package        AutoALT for Joomla! 3.x
 * @author         Sergey Bunin <sebun@mail.ru>
 * @version        1.0 - 2025-04-22
 * @link           https://sebun.ru
 *
 * Автоматически добавляет ALT и TITLE к изображениям на основе заголовка страницы. Поддерживает исключение по
 * компонентам, маскам, редактору и пользователям.
 */
defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Editor\Editor;
use Joomla\CMS\Uri\Uri;

class PlgSystemAutoAlt extends CMSPlugin
{
    /** @var CMSApplication */
    protected $app;

    /** @var bool */
    protected $execute = false;

    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        $this->app     = Factory::getApplication();
        $this->execute = $this->app->isClient('site');
    }

    /**
     * Точка входа после рендера страницы
     */
    public function onAfterRender(): void
    {
        if (!$this->checkExecution()) {
            return;
        }

        $body = $this->app->getBody(false);
        if (empty($body)) {
            return;
        }

        // Выделим только <body>…</body>, если нужно
        if (preg_match('@<body[^>]*>.*</body>@Uis', $body, $m)) {
            $bodyOnly = $m[0];
        } else {
            $bodyOnly = $body;
        }

        // Обработка изображений
        if ((int)$this->params->get('editImages', 0) > 0) {
            $this->editImages($bodyOnly, $body);
        }

        $this->app->setBody($body);
    }

    /**
     * Проверяем, запускать ли плагин
     */
    private function checkExecution(): bool
    {
        if (!$this->execute || Factory::getDocument()->getType() !== 'html') {
            return false;
        }

        // Исключаем визуальный редактор
        if ((bool)$this->params->get('excludeEditor', true) && class_exists(Editor::class, false)) {
            return false;
        }

        // Исключаем авторизованных пользователей
        if ((bool)$this->params->get('excludeUser', false) && !Factory::getUser()->guest) {
            return false;
        }

        // Исключаем по компоненту
        if ($this->params->get('excludeComponents', '') && $this->excludeLoadedComponent()) {
            return false;
        }

        return true;
    }

    /**
     * Проверка excludeComponents / toggle
     */
    private function excludeLoadedComponent(): bool
    {
        $option            = $this->app->input->getCmd('option');
        $list              = array_filter(array_map('trim', explode("\n", $this->params->get('excludeComponents', ''))));
        $found             = in_array($option, $list, true);
        $toggle            = (bool)$this->params->get('excludeComponentsToggle', false);
        return $toggle ? !$found : $found;
    }

    /**
     * Основная обработка <img>…>
     *
     * @param string $bodyOnly
     * @param string &$body
     */
    private function editImages(string $bodyOnly, string &$body): void
    {
        // Шаблон масок исключения по src
        $masks       = array_filter(array_map('trim', explode("\n", $this->params->get('exclude_masks', ''))));
        // Найдём все теги <img …>
        preg_match_all('@<img\s+([^>]*src\s*=\s*[\'"][^\'"]+[\'"][^>]*)>@Ui', $bodyOnly, $images, PREG_OFFSET_CAPTURE);

        foreach ($images[1] as $i => $attrMatch) {
            $fullTag = $images[0][$i][0];
            $attrs   = $attrMatch[0];

            // Пропустить, если уже есть alt и не ставим overwrite
            $hasAlt = preg_match('/\balt\s*=\s*["\'].*?["\']/i', $attrs);
            if ($hasAlt && !(bool)$this->params->get('overwriteImages', false)) {
                continue;
            }

            // Получим URL src
            preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $attrs, $s);
            $src = $s[1] ?? '';

            // Исключение по маске
            foreach ($masks as $mask) {
                if ($mask !== '' && stripos($src, $mask) !== false) {
                    continue 2;
                }
            }

            // ALT / TITLE будем брать из заголовка документа
            $pageTitle = Factory::getDocument()->getTitle();
            $text      = htmlspecialchars(trim(strip_tags($pageTitle)), ENT_QUOTES);

            // Добавляем alt
            if ($this->params->get('editImages', 0) >= 1) {
                // либо replace существующего alt, либо add
                if ($hasAlt) {
                    $fullTag = preg_replace('/\balt\s*=\s*["\'].*?["\']/i', 'alt="' . $text . '"', $fullTag);
                } else {
                    $fullTag = preg_replace('/<img\s+/i', '<img alt="' . $text . '" ', $fullTag, 1);
                }
            }

            // Добавляем title, если нужно
            if ($this->params->get('editImages', 0) == 2) {
                $hasTitle = preg_match('/\btitle\s*=\s*["\'].*?["\']/i', $fullTag);
                if ($hasTitle && !$this->params->get('overwriteImages', false)) {
                    // оставляем
                } elseif ($hasTitle) {
                    $fullTag = preg_replace('/\btitle\s*=\s*["\'].*?["\']/i', 'title="' . $text . '"', $fullTag);
                } else {
                    $fullTag = preg_replace('/<img\s+/i', '<img title="' . $text . '" ', $fullTag, 1);
                }
            }

            // Добавляем размеры, если нужно
            if ((bool)$this->params->get('addSize', true)) {
                $fullTag = $this->addImageSize($fullTag, $src);
            }

            // Внедряем обратно в тело
            $body = str_replace($images[0][$i][0], $fullTag, $body);
        }
    }

    /**
     * Добавляет width/height к тегу, если их нет
     */
    private function addImageSize(string $tag, string $src): string
    {
        // Пропустить, если и так есть width&height
        if (preg_match('/\bwidth\s*=\s*["\']\d+["\']/i', $tag)
            && preg_match('/\bheight\s*=\s*["\']\d+["\']/i', $tag)
        ) {
            return $tag;
        }

        // Вычислим относительный путь
        $base   = Uri::base(true);
        $path   = $src;
        if (strpos($src, Uri::base()) === 0) {
            $path = substr($src, strlen(Uri::base()));
        } elseif ($src[0] === '/') {
            $path = substr($src, 1);
        }
        $file = JPATH_SITE . '/' . ltrim($path, '/');

        if (is_file($file)) {
            $size = @getimagesize($file);
            if (!empty($size)) {
                list($w, $h) = $size;
                if (!preg_match('/\bwidth\s*=\s*["\']\d+["\']/i', $tag)) {
                    $tag = preg_replace('/<img\s+/i', '<img width="' . $w . '" ', $tag, 1);
                }
                if (!preg_match('/\bheight\s*=\s*["\']\d+["\']/i', $tag)) {
                    $tag = preg_replace('/<img\s+/i', '<img height="' . $h . '" ', $tag, 1);
                }
            }
        }

        return $tag;
    }
}
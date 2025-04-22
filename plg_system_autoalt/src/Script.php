<?php

/**
 * @copyright
 * @package        AutoALT for Joomla! 3.x
 * @author         Sergey Bunin <sebun@mail.ru>
 * @version        1.0 - 2025-04-22
 * @link           https://sebun.ru
 *
 * Инсталляционный скрипт, который Joomla автоматически подхватывает во время установки/обновления плагина. Его основная
 * задача — проверить, что система соответствует минимальным требованиям, и в случае несоответствия прервать процесс с
 * показом понятного сообщения.
 */
defined('_JEXEC') || die('Restricted access');

/**
 * Class PlgSystemSeofliInstallerScript
 *
 * @since 1.0
 */
class PlgSystemSeofliInstallerScript
{
    /**
     * Задаем минимальные требования для установки
     */
    public const MIN_VERSION_JOOMLA = '3.9.0';
    public const MIN_VERSION_PHP = '7.3.0';

    /**
     * Имя расширения, которое используется в сообщении об ошибке
     *
     * @var string
     * @since 1.0
     */
    protected $extensionName = 'AutoALT for Joomla! 3.x';

    /**
     * Проверяем совместимость до того, как начнётся установка или обновление. Возвращает false, если какая‑то из
     * проверок не прошла, и тем самым блокирует дальнейший процесс.
     *
     * @param $type
     * @param $parent
     *
     * @return bool
     * @throws Exception
     * @since 1.0
     */
    public function preflight($type, $parent)
    {
        if (!$this->checkVersionJoomla()) {
            return false;
        }

        if (!$this->checkVersionPhp()) {
            return false;
        }

        return true;
    }

    /**
     * Проверяет, соответствует ли версия Joomla! требованиям. использует устаревший класс JVersion, чтобы без ошибок в
     * старых сборках проверить, что текущая Joomla ≥ 3.9.0. Если нет — ставит в очередь сообщение об ошибке и
     * возвращает false.
     *
     * @return bool
     * @throws Exception
     * @since   1.0
     */
    private function checkVersionJoomla()
    {
        // Используем устаревшие версии JFactory и классы JText, чтобы избежать ошибок в старых версиях Joomla!
        $version = new JVersion();

        if (!$version->isCompatible(self::MIN_VERSION_JOOMLA)) {
            JFactory::getApplication()->enqueueMessage(JText::sprintf('AUTOALT_ERROR_JOOMLA_VERSION', $this->extensionName, self::MIN_VERSION_JOOMLA), 'error');

            return false;
        }

        return true;
    }

    /**
     * Проверяем, соответствует ли версия PHP заданным требованиям
     *
     * @return bool
     * @throws Exception
     * @since   1.0
     */
    private function checkVersionPhp()
    {
        if (!version_compare(phpversion(), self::MIN_VERSION_PHP, 'ge')) {
            JFactory::getApplication()->enqueueMessage(JText::sprintf('AUTOALT_ERROR_PHP_VERSION', $this->extensionName, self::MIN_VERSION_PHP), 'error');

            return false;
        }

        return true;
    }
}

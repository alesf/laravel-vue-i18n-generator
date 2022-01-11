<?php

declare(strict_types=1);

namespace Megaverse\VueInternationalizationGenerator;

use DirectoryIterator;
use Exception;
use App;
use SplFileInfo;

class Generator
{
    private array $config;

    private array $availableLocales = [];

    private array $filesToCreate = [];

    private array $langFiles = [];

    protected const VUEX_I18N = 'vuex-i18n';
    protected const VUE_I18N = 'vue-i18n';
    protected const ESCAPE_CHAR = '!';

    public function __construct(array $config = [])
    {
        if (! isset($config['i18nLib'])) {
            $config['i18nLib'] = self::VUE_I18N;
        }

        if (! isset($config['excludes'])) {
            $config['excludes'] = [];
        }

        if (! isset($config['escape_char'])) {
            $config['escape_char'] = self::ESCAPE_CHAR;
        }

        if (! isset($config['fallback_locale'])) {
            $config['fallback_locale'] = config('app.fallback_locale');
        }

        $this->config = $config;
    }

    /**
     * @throws \JsonException
     * @throws \Exception
     */
    public function generateFromPath(
        string $path,
        string $format = 'es6',
        bool $withVendor = false,
        ?array $langFiles = []
    ): string {
        if (! is_dir($path)) {
            throw new Exception('Directory not found: ' . $path);
        }

        if ($langFiles) {
            $this->langFiles = $langFiles;
        }

        $locales = [];
        $files = [];
        $dir = new DirectoryIterator($path);

        foreach ($dir as $fileInfo) {
            if (! $fileInfo->isDot()) {
                if (! $withVendor && in_array($fileInfo->getFilename(), array_merge(['vendor'], $this->config['excludes']), true)) {
                    continue;
                }

                $files[] = $fileInfo->getRealPath();
            }
        }

        asort($files);

        foreach ($files as $fileName) {
            $fileInfo = new SplFileInfo($fileName);
            $noExtension = $this->removeExtension($fileInfo->getFilename());

            if ($noExtension !== '') {
                if (class_exists('App')) {
                    App::setLocale($noExtension);
                }

                if ($fileInfo->isDir()) {
                    $local = $this->allocateLocaleArray($fileInfo->getRealPath());
                } else {
                    $local = $this->allocateLocaleJSON($fileInfo->getRealPath());

                    if ($local === null) {
                        continue;
                    }
                }

                if (isset($locales[$noExtension])) {
                    $locales[$noExtension] = array_merge($local, $locales[$noExtension]);
                } else {
                    $locales[$noExtension] = $local;
                }
            }
        }

        $locales = $this->adjustVendor($locales);

        if ($fallbackLocale = $locales[$this->config['fallback_locale']] ?? null) {
            foreach ($locales as $locale => $data) {
                if ($locale !== $this->config['fallback_locale']) {
                    $locales[$locale] = $this->addFallbackLocaleKeys($data, $fallbackLocale);
                }
            }
        }

        return $this->encodeJson($locales, $format);
    }

    /**
     * @throws \JsonException
     * @throws \Exception
     */
    public function generateMultiple(string $path, string $format = 'es6', bool $multiLocales = false): string
    {
        if (! is_dir($path)) {
            throw new Exception('Directory not found: ' . $path);
        }

        $jsPath = base_path() . $this->config['jsPath'];
        $locales = [];
        $createdFiles = '';
        $dir = new DirectoryIterator($path);

        foreach ($dir as $fileInfo) {
            if ($fileInfo !== ''
                && ! $fileInfo->isDot()
                && ! in_array($fileInfo->getFilename(), array_merge(['vendor'], $this->config['excludes']), true)
            ) {
                $noExtension = $this->removeExtension($fileInfo->getFilename());

                if ($noExtension !== '') {
                    if (class_exists('App')) {
                        App::setLocale($noExtension);
                    }

                    if (! in_array($noExtension, $this->availableLocales, true)) {
                        $this->availableLocales[] = $noExtension;
                    }

                    if ($fileInfo->isDir()) {
                        $local = $this->allocateLocaleArray($fileInfo->getRealPath(), $multiLocales);
                    } else {
                        $local = $this->allocateLocaleJSON($fileInfo->getRealPath());

                        if ($local === null) {
                            continue;
                        }
                    }

                    if (isset($locales[$noExtension])) {
                        $locales[$noExtension] = array_merge($local, $locales[$noExtension]);
                    } else {
                        $locales[$noExtension] = $local;
                    }
                }
            }
        }

        $fallbackLocale = $this->filesToCreate[$this->config['fallback_locale']][$this->config['fallback_locale']] ?? null;

        foreach ($this->filesToCreate as $fileName => $data) {
            if ($fileName !== $this->config['fallback_locale'] && $fallbackLocale) {
                $data[$fileName] = $this->addFallbackLocaleKeys($data[$fileName], $fallbackLocale);
            }

            $fileToCreate = $jsPath . $fileName . '.js';
            $createdFiles .= $fileToCreate . PHP_EOL;
            $jsBody = $this->encodeJson($data, $format);

            if (! is_dir(dirname($fileToCreate))) {
                mkdir(dirname($fileToCreate), 0777, true);
            }

            file_put_contents($fileToCreate, $jsBody);
        }

        return $createdFiles;
    }

    /**
     * @throws \Exception
     */
    protected function encodeJson(array $data, string $format): string
    {
        $jsonLocales = json_encode($data,JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Could not generate JSON, error code ' . json_last_error());
        }

        if ($format === 'es6') {
            $jsBody = $this->getES6Module($jsonLocales);
        } elseif ($format === 'umd') {
            $jsBody = $this->getUMDModule($jsonLocales);
        } else {
            $jsBody = $jsonLocales;
        }

        return $jsBody;
    }

    protected function allocateLocaleJSON(string $path): ?array
    {
        // Ignore non *.json files (ex.: .gitignore, vim swap files etc.)
        if (pathinfo($path, PATHINFO_EXTENSION) !== 'json') {
            return null;
        }

        $data = (array) json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new Exception('Unexpected data while processing ' . $path);
        }

        return $this->adjustArray($data);
    }

    protected function allocateLocaleArray(string $path, bool $multiLocales = false): array
    {
        $data = [];
        $dir = new DirectoryIterator($path);
        $lastLocale = last($this->availableLocales);

        foreach ($dir as $fileInfo) {
            // Do not mess with dotfiles at all.
            if ($fileInfo->isDot()) {
                continue;
            }

            if ($fileInfo->isDir()) {
                // Recursivley iterate through subdirs, until everything is allocated.
                $data[$fileInfo->getFilename()] = $this->allocateLocaleArray($path . DIRECTORY_SEPARATOR . $fileInfo->getFilename());
            } else {
                $noExt = $this->removeExtension($fileInfo->getFilename());
                $fileName = $path . DIRECTORY_SEPARATOR . $fileInfo->getFilename();

                // Ignore non *.php files (ex.: .gitignore, vim swap files etc.)
                if (pathinfo($fileName, PATHINFO_EXTENSION) !== 'php') {
                    continue;
                }

                if ($this->shouldIgnoreLangFile($noExt)) {
                    continue;
                }

                $content = include($fileName);

                if (! is_array($content)) {
                    throw new Exception('Unexpected data while processing ' . $fileName);
                }

                if ($lastLocale !== false) {
                    $root = realpath(base_path() . $this->config['langPath'] . DIRECTORY_SEPARATOR . $lastLocale);
                    $filePath = $this->removeExtension(str_replace('\\', '_',
                        ltrim(str_replace($root, '', realpath($fileName)), '\\')));

                    if ($filePath[0] === DIRECTORY_SEPARATOR) {
                        $filePath = substr($filePath, 1);
                    }

                    if ($multiLocales) {
                        $this->filesToCreate[$lastLocale][$lastLocale][$filePath] = $this->adjustArray($content);
                    } else {
                        $this->filesToCreate[$filePath][$lastLocale] = $this->adjustArray($content);
                    }
                }

                $data[$noExt] = $this->adjustArray($content);
            }
        }

        return $data;
    }

    protected function shouldIgnoreLangFile(string $noExtension): bool
    {
        // langFiles passed by option have priority
        if (isset($this->langFiles) && ! empty($this->langFiles)) {
            return ! in_array($noExtension, $this->langFiles, true);
        }

        return (isset($this->config['langFiles']) && ! empty($this->config['langFiles']) && ! in_array($noExtension, $this->config['langFiles'], true))
            || (isset($this->config['excludes']) && in_array($noExtension, $this->config['excludes'], true));
    }

    protected function adjustArray(array $array): array
    {
        $result = [];

        foreach ($array as $key => $val) {
            $key = $this->removeEscapeCharacter($this->adjustString($key));

            if (is_array($val)) {
                $result[$key] = $this->adjustArray($val);
            } else {
                $result[$key] = $this->removeEscapeCharacter($this->adjustString($val));
            }
        }

        return $result;
    }

    protected function adjustVendor(array $locales): array
    {
        if (isset($locales['vendor'])) {
            foreach ($locales['vendor'] as $vendor => $data) {
                foreach ($data as $key => $group) {
                    foreach ($group as $locale => $lang) {
                        $locales[$key]['vendor'][$vendor][$locale] = $lang;
                    }
                }
            }

            unset($locales['vendor']);
        }

        return $locales;
    }

    /**
     * Turn Laravel style ":link" into vue-i18n style "{link}" or vuex-i18n style ":::".
     */
    protected function adjustString(string $string): string
    {
        if (! is_string($string)) {
            return $string;
        }

        if ($this->config['i18nLib'] === self::VUEX_I18N) {
            $searchPipePattern = '/(\s)*(\|)(\s)*/';
            $threeColons = ' ::: ';

            $string = preg_replace($searchPipePattern, $threeColons, $string);
        }

        $escaped_escape_char = preg_quote($this->config['escape_char'], '/');

        return preg_replace_callback(
            "/(?<!mailto|tel|$escaped_escape_char):\w+/",
            static function ($matches) {
                return '{' . mb_substr($matches[0], 1) . '}';
            },
            $string
        );
    }

    /**
     * Removes escape character if translation string contains sequence that looks like
     * Laravel style ":link", but should not be interpreted as such and was therefore escaped.
     */
    protected function removeEscapeCharacter(string $string): string
    {
        $escaped_escape_char = preg_quote($this->config['escape_char'], '/');

        return preg_replace_callback(
            "/$escaped_escape_char(:\w+)/",
            static function ($matches) {
                return mb_substr($matches[0], 1);
            },
            $string
        );
    }

    /**
     * Returns filename, with extension stripped
     */
    protected function removeExtension(string $filename): string
    {
        $position = mb_strrpos($filename, '.');

        if ($position === false) {
            return $filename;
        }

        return mb_substr($filename, 0, $position);
    }

    /**
     * Returns an UMD style module.
     */
    protected function getUMDModule(string $body): string
    {
        return <<<HEREDOC
(function (global, factory) {
    typeof exports === 'object' && typeof module !== 'undefined' ? module.exports = factory() :
        typeof define === 'function' && define.amd ? define(factory) :
            typeof global.vuei18nLocales === 'undefined' ? global.vuei18nLocales = factory() : Object.keys(factory()).forEach(function (key) {global.vuei18nLocales[key] = factory()[key]});
}(this, (function () { 'use strict';
    return $body
})));
HEREDOC;
    }

    /**
     * Returns an ES6 style module.
     */
    protected function getES6Module(string $body): string
    {
        return <<<HEREDOC
const translations = $body
window.translations = translations;

export default translations;
HEREDOC;
    }

    protected function addFallbackLocaleKeys(array $locale, array &$fallbackLocale): array
    {
        $merged = $locale;

        foreach ($fallbackLocale as $key => &$value) {
            if (is_array($value)) {
                if (! isset($merged[$key]) || ! is_array($merged[$key])) {
                    $merged[$key] = $value;

                    continue;
                }

                $merged[$key] = $this->addFallbackLocaleKeys($merged[$key], $value);
            } else if (! isset($merged[$key])) {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }
}

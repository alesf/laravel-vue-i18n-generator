<?php

declare(strict_types=1);

namespace Megaverse\VueInternationalizationGenerator\Commands;

use Illuminate\Console\Command;

use Megaverse\VueInternationalizationGenerator\Generator;
use RuntimeException;

class GenerateInclude extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vue-i18n:generate {--umd} {--multi} {--with-vendor} {--file-name=} {--lang-files=} {--format=es6} {--multi-locales}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Generates a vue-i18n|vuex-i18n compatible js array out of project translations";

    public function handle(): int
    {
        $root = base_path() . config('vue-i18n-generator.langPath');
        $config = config('vue-i18n-generator');

        // options
        $umd = $this->option('umd');
        $multipleFiles = $this->option('multi');
        $withVendor = $this->option('with-vendor');
        $fileName = $this->option('file-name');
        $langFiles = $this->option('lang-files');
        $format = $this->option('format');
        $multipleLocales = $this->option('multi-locales');

        if ($umd) {
            // if the --umd option is set, set the $format to 'umd'
            $format = 'umd';
        }

        if (!$this->isValidFormat($format)) {
            throw new RuntimeException('Invalid format passed: ' . $format);
        }

        if ($multipleFiles || $multipleLocales) {
            $files = (new Generator($config))
                ->generateMultiple($root, $format, $multipleLocales);

            if ($config['showOutputMessages']) {
                $this->info("Written to : " . $files);
            }

            return 0;
        }

        if ($langFiles) {
            $langFiles = explode(',', $langFiles);
        }

        $data = (new Generator($config))
            ->generateFromPath($root, $format, $withVendor, $langFiles);


        $jsFile = $this->getFileName($fileName);

        file_put_contents($jsFile, $data);

        if ($config['showOutputMessages']) {
            $this->info("Written to : " . $jsFile);
        }

        return 0;
    }

    private function getFileName(?string $fileNameOption): string
    {
        if (isset($fileNameOption)) {
            return base_path() . $fileNameOption;
        }

        return base_path() . config('vue-i18n-generator.jsFile');
    }

    private function isValidFormat(string $format): bool
    {
        return in_array($format, ['es6', 'umd', 'json']);
    }
}

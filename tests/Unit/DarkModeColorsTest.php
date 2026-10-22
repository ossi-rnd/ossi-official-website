<?php

// In dark mode the 500-700 shades of blue, red, indigo… are under WCAG's 4.5:1 on our dark
// backgrounds (blue-600 on a gray-900 card: 3.4), while every -400 passes. So a class list that
// colours text (or its hover) 500-700 must say what it is in dark mode too, e.g.
// "text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300".
// Checkboxes are left out: there text- is the tick box's fill, not text.

it('gives every coloured text a dark-mode colour', function () {
    $colors = 'blue|red|amber|green|orange|emerald|indigo|purple|sky|teal|rose';
    $missing = [];
    $views = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views';

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views));
    foreach ($files as $file) {
        if (! str_ends_with((string) $file, '.blade.php')) {
            continue;
        }

        preg_match_all('/class="([^"{}]*)"/', (string) file_get_contents((string) $file), $lists);
        foreach ($lists[1] as $list) {
            if (str_contains($list, 'focus:ring-blue-500') && str_contains($list, 'rounded border-gray-300')) {
                continue;
            }
            $base = preg_match('/(?<![\w:-])text-(?:'.$colors.')-[5-7]00(?![\w-])/', $list) === 1;
            $hover = preg_match('/(?<![\w:-])hover:text-(?:'.$colors.')-[5-7]00(?![\w-])/', $list) === 1;

            if (($base && ! str_contains($list, 'dark:text-')) || ($hover && ! str_contains($list, 'dark:hover:text-'))) {
                $missing[] = str_replace($views.DIRECTORY_SEPARATOR, '', (string) $file).': '.$list;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('never darkens text on hover in dark mode', function () {
    // hover:text-gray-700/800/900 is right on white, but on a dark card it nearly vanishes
    // ("Set date", back links). Such a list must also say its dark-mode hover.
    $views = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views';
    $missing = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views)) as $file) {
        if (! str_ends_with((string) $file, '.blade.php')) {
            continue;
        }

        // class="..." attributes, and single-line quoted strings made only of class names
        // (in @class([...]) and x-bind:class). Never a span across two separate strings.
        $source = (string) file_get_contents((string) $file);
        preg_match_all('/class="([^"\n{}]*)"/', $source, $attributes);
        preg_match_all("/'([^'\\n]*)'/", $source, $quoted);
        $classOnly = array_filter($quoted[1], fn (string $text) => preg_match('/^[\w:\-\/\[\]\.!%#&>*~=()]+(?: +[\w:\-\/\[\]\.!%#&>*~=()]+)*$/', trim($text)) === 1);

        foreach ([...$attributes[1], ...$classOnly] as $list) {
            if (preg_match('/(?<![\w:-])hover:text-gray-[7-9]00(?![\w-])/', $list) === 1 && ! str_contains($list, 'dark:hover:text-')) {
                $missing[] = str_replace($views.DIRECTORY_SEPARATOR, '', (string) $file).': '.$list;
            }
        }
    }

    // The shared button styles live in PHP arrays, so check the ghost variant too.
    $button = (string) file_get_contents($views.DIRECTORY_SEPARATOR.'components'.DIRECTORY_SEPARATOR.'ui'.DIRECTORY_SEPARATOR.'button.blade.php');
    expect($button)->toContain("'ghost' => 'text-gray-600 hover:bg-gray-100 hover:text-gray-800 focus-visible:ring-gray-400 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white'")
        ->and($missing)->toBe([]);
});

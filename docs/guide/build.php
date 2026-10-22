<?php

// Run: php docs/guide/build.php  →  docs/guide/ossi-build-guide.html
// Builds the study guide: every @@code(path|open)@@ marker becomes the REAL file from the repo.
$repo = dirname(__DIR__, 2).'/';
$dir = __DIR__;
$template = file_get_contents($dir.'/template.html');

function highlight(string $code): string
{
    $pattern = '~(?<c>/\*[\s\S]*?\*/|//[^\n]*|\{\{--[\s\S]*?--\}\})'
        .'|(?<s>\'(?:\\\\.|[^\'\\\\\n])*\'|"(?:\\\\.|[^"\\\\\n])*")'
        .'|(?<k>\b(?:public|private|protected|function|return|final|class|readonly|use|namespace|new|if|else|elseif|foreach|for|match|fn|throw|static|const|true|false|null|interface|implements|extends|try|catch|as|default|await|async|let|import|export|while|enum|case|trait)\b)'
        .'|(?<f>\b[a-zA-Z_]\w*(?=\())'
        .'|(?<n>\b\d+(?:\.\d+)?\b)~';

    $out = '';
    $offset = 0;

    preg_match_all($pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

    foreach ($matches as $match) {
        [$text, $position] = $match[0];
        $out .= htmlspecialchars(substr($code, $offset, $position - $offset), ENT_QUOTES);

        foreach (['c', 's', 'k', 'f', 'n'] as $class) {
            if (isset($match[$class]) && $match[$class][1] !== -1 && $match[$class][0] !== '') {
                $out .= '<span class="'.$class.'">'.htmlspecialchars($text, ENT_QUOTES).'</span>';
                break;
            }
        }

        $offset = $position + strlen($text);
    }

    return $out.htmlspecialchars(substr($code, $offset), ENT_QUOTES);
}

$html = preg_replace_callback('/@@code\(([^|)]+)(\|open)?\)@@/', function (array $m) use ($repo) {
    $path = $m[1];
    $file = $repo.$path;

    if (! is_file($file)) {
        fwrite(STDERR, "MISSING {$path}\n");
        exit(1);
    }

    $code = str_replace("\r\n", "\n", rtrim(file_get_contents($file)));
    $lines = substr_count($code, "\n") + 1;
    $open = isset($m[2]) && $m[2] !== '' ? ' open' : '';

    return "<details class=\"file\"{$open}><summary>{$path}<span class=\"lines\">{$lines} lines</span></summary>"
        .'<div class="bar"><span>Copied from the repository as it is today</span><button type="button" data-copy>Copy</button></div>'
        .'<pre><code>'.highlight($code).'</code></pre></details>';
}, $template);

file_put_contents($dir.'/ossi-build-guide.html', $html);
echo 'built '.strlen($html)." bytes\n";

// Never publish a secret.
foreach (['waxufjkworpqfemo', 'waxu fjkw', 'base64:grx'] as $secret) {
    if (stripos($html, $secret) !== false) {
        fwrite(STDERR, "SECRET FOUND: {$secret}\n");
        exit(1);
    }
}
echo "no secrets\n";

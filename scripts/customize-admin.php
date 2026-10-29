<?php

// The admin submodule only distributes built assets. These replacements target
// its pinned a260c84d build; other builds must be reviewed before patching.
function replaceAdminFragment(string $source, string $before, string $after): string
{
    $result = str_replace($before, $after, $source, $count);
    if ($count !== 1) {
        throw new RuntimeException('Admin patch expected one occurrence: ' . substr($before, 0, 100));
    }
    return $result;
}

try {
    $root = dirname(__DIR__);
    $admin = $root . '/public/assets/admin';
    $originalFile = 'assets/index-CEIYH7i8.js';
    $upstreamHash = 'f04f09a95bfdb04fa5132e336bf87c364d4e360cb95774872ff18eb69e9bcd77';
    $bundle = file_get_contents($admin . '/' . $originalFile);
    if (hash('sha256', $bundle) !== $upstreamHash) {
        throw new RuntimeException('Unsupported admin bundle. Review the upstream changes before updating this patch.');
    }

    $start = strpos($bundle, 'function $$t(){');
    $end = $start === false ? false : strpos($bundle, 'function q$t(', $start);
    if ($start === false || $end === false) {
        throw new RuntimeException('Usage overview component not found.');
    }
    $overview = substr($bundle, $start, $end - $start);
    foreach ([
        // Keep calendar dates throughout this card. LS/ES can use different
        // browser UTC offsets on a DST boundary and shift the selected day.
        ['const n=ES(new Date);', 'const n=XboardUsageToday();'],
        ['return{startDate:LS(e),endDate:LS(n)}', 'return{startDate:e,endDate:n}'],
        ['return{startDate:LS(i),endDate:LS(n)}', 'return{startDate:i,endDate:n}'],
        ['c=e=>_S(ES(e),"yyyy-MM-dd")', 'c=e=>_S(e,"yyyy-MM-dd")'],
        ['H.useState("amount")', 'H.useState("users")'],
        ['{data:d}=pC', '{data:d,isLoading:usageLoading,isError:usageError,refetch:retryUsage}=pC'],
        ['queryKey:["orderStat"', 'queryKey:["usageStat"'],
        ['await AL({start_date:c(a),end_date:c(l)})', 'await IL(ML+"/stat/getUsage",{params:{start_date:c(a),end_date:c(l)}})'],
        // Upstream polls this card every 30s because its order totals change
        // during the day and cost one row per day. These daily aggregates scan
        // users x rates x days twice per request, so drop the timer and refetch
        // on mount, on a range change, or on focus once the data is stale.
        ['refetchInterval:3e4', 'staleTime:3e5'],
        ['o("dashboard:overview.title")', 'o("dashboard:usage.title")'],
        ['value:"amount",children:o("dashboard:overview.amount")', 'value:"users",children:o("dashboard:usage.users")'],
        ['value:"count",children:o("dashboard:overview.count")', 'value:"traffic",children:o("dashboard:usage.traffic")'],
        ['U$t.map(e=>Q.jsx(', 'U$t.filter(e=>["7d","30d","custom"].includes(e.value)).map(e=>Q.jsx('],
        // available_from is already a calendar date; bounds use the same
        // application calendar day as the preset ranges.
        ['captionLayout:"dropdown",numberOfMonths:2', 'captionLayout:"dropdown",numberOfMonths:2,disabled:[{before:d?.summary?.available_from?new Date(d.summary.available_from+"T00:00:00"):bS(XboardUsageToday(),29)},{after:XboardUsageToday()}]'],
        ['className:"flex items-center justify-between"', 'className:"flex flex-wrap items-center justify-between gap-4"'],
        ['className:"flex items-center gap-2"', 'className:"flex flex-wrap items-center gap-2"'],
        ['className:"flex min-w-0 items-center gap-1"', 'className:"flex min-w-0 flex-wrap items-center gap-1"'],
    ] as [$before, $after]) {
        $overview = replaceAdminFragment($overview, $before, $after);
    }

    // Retain the existing date presets and custom range picker.
    $content = strpos($overview, 'Q.jsxs(c8e,{children:');
    if ($content === false) {
        throw new RuntimeException('Overview card content not found.');
    }
    $overview = substr($overview, 0, $content)
        . 'Q.jsx(c8e,{children:Q.jsx(XboardUsageContent,{data:d,metric:e,loading:usageLoading,error:usageError,retry:retryUsage})})]})}';
    $bundle = substr($bundle, 0, $start) . $overview . substr($bundle, $end);

    $statusDot = 'Q.jsx("div",{className:Im("size-2.5 rounded-full ring-2 ring-offset-2",s?"bg-green-500 ring-green-500/20":"bg-gray-300 ring-gray-300/20","transition-all duration-300")})';
    $bundle = replaceAdminFragment($bundle, $statusDot,
        'Q.jsx(XboardOnlineIPs,{user:e,className:"h-5 w-5 shrink-0",children:' . $statusDot . '})');

    $devicesStart = strpos($bundle, 'cell:({row:e})=>{const t=e.original.device_limit,n=e.original.online_count||0;');
    $devicesEnd = $devicesStart === false ? false : strpos($bundle, '},enableSorting:!0,enableHiding:!1}', $devicesStart);
    if ($devicesStart === false || $devicesEnd === false) {
        throw new RuntimeException('User device cell not found.');
    }
    $bundle = substr($bundle, 0, $devicesStart)
        . 'cell:({row:e})=>Q.jsx(XboardUserDevices,{user:e.original,t:r})' . substr($bundle, $devicesEnd + 1);

    $translations = file_get_contents($root . '/resources/js/admin/usage-locales.json');
    json_decode($translations, true, 512, JSON_THROW_ON_ERROR);
    $localeSetup = "for (const [language, additions] of Object.entries({$translations})) {\n"
        . "  const resources = window.XBOARD_TRANSLATIONS?.[language];\n"
        . "  if (!resources) continue;\n"
        . "  resources.dashboard.usage = additions.usage;\n"
        . "  resources.user.onlineIPs = additions.onlineIPs;\n}\n";
    $components = file_get_contents($root . '/resources/js/admin/usage-components.js');
    $output = $localeSetup . $bundle . "\n" . $components;
    $file = 'assets/index-usage-' . substr(hash('sha256', $output), 0, 12) . '.js';

    $manifestPath = $admin . '/manifest.json';
    $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $previousFile = $manifest['index.html']['file'];
    $isCustomized = preg_match('#^assets/index-usage-[a-f0-9]{12}\.js$#', $previousFile) === 1;
    if ($previousFile !== $originalFile && !$isCustomized) {
        throw new RuntimeException('The admin manifest has unrelated entry changes.');
    }
    $htmlPath = $admin . '/index.html';
    $html = replaceAdminFragment(file_get_contents($htmlPath), './' . $previousFile, './' . $file);

    if (file_put_contents($admin . '/' . $file, $output) === false) {
        throw new RuntimeException('Unable to write the customized admin bundle.');
    }
    $manifest['index.html']['file'] = $file;
    if (file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false
        || file_put_contents($htmlPath, $html) === false) {
        throw new RuntimeException('Unable to update the admin entry.');
    }
    foreach (glob($admin . '/assets/index-usage-*.js') ?: [] as $stale) {
        $name = basename($stale);
        if ($name === basename($file) || preg_match('#^index-usage-([a-f0-9]{12})\.js$#', $name, $match) !== 1) {
            continue;
        }
        // Only remove files this script generated, identified by their own content hash.
        if ($match[1] === substr(hash_file('sha256', $stale), 0, 12)) {
            unlink($stale);
        }
    }
    echo "Built {$file}\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

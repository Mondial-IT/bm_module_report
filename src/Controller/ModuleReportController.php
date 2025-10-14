<?php

declare(strict_types=1);

namespace Drupal\bm_module_report\Controller;

use Composer\InstalledVersions;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

final class ModuleReportController extends ControllerBase {

  public function __construct(
    private readonly ModuleExtensionList $moduleList,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('extension.list.module'),
    );
  }

  /**
   * Report page.
   */
  public function report(): array {
    $extensions = $this->moduleList->reset()->getList();
    $packageMap = $this->buildComposerPackageMap();

    $items = [];
    /** @var \Drupal\Core\Extension\Extension $ext */
    foreach ($extensions as $machineName => $ext) {
      if ($this->isCoreModule($ext)) {
        continue;
      }
      [$origin, $composerRequireBase] = $this->detectComposerOrigin($ext, $packageMap);
      $enabled = $this->moduleHandler()->moduleExists($machineName);

      $pkg = $this->asComposerPackageOrNull($origin);
      $pretty = $pkg ? $this->getPrettyVersion($pkg) : null;
      $constraint = $pretty ? $this->toCaretConstraint($pretty) : null;

      $requireLine = $composerRequireBase
        ? $composerRequireBase . ($constraint ? ' ' . $constraint : '')
        : null;

      $items[] = [
        'label'         => $ext->info['name'] ?? $machineName,
        'machine'       => $machineName,
        'origin'        => $origin,
        'package'       => $pkg,
        'pretty'        => $pretty,
        'constraint'    => $constraint,
        'require_line'  => $requireLine,
        'enabled'       => $enabled,
      ];
    }

    // For each composer package, is any module enabled?
    $packageAnyEnabled = [];
    foreach ($items as $row) {
      if ($row['package']) {
        $packageAnyEnabled[$row['package']] = ($packageAnyEnabled[$row['package']] ?? false) || $row['enabled'];
      }
    }

    // Sort: column 1 (require_line) then column 2 (label).
    usort($items, function (array $a, array $b): int {
      $ra = $a['require_line'] ?: "\xFF\xFFzzzz";
      $rb = $b['require_line'] ?: "\xFF\xFFzzzz";
      $cmp = strnatcasecmp($ra, $rb);
      return $cmp !== 0 ? $cmp : strnatcasecmp($a['label'], $b['label']);
    });

    // Download button as markup.
    $download_url = Url::fromRoute('bm_module_report.download', [])
      ->setOption('attributes', ['class' => ['button', 'button--primary']]);
    $download_link_markup = Link::fromTextAndUrl(
      $this->t('Download composer.json lines (enabled packages)'),
      $download_url
    )->toString();

    $rows = [];
    foreach ($items as $row) {
      $row_classes = [];
      if ($row['package'] && empty($packageAnyEnabled[$row['package']])) {
        $row_classes[] = 'bm-yellow'; // whole package disabled
      }

      $rows[] = [
        'data' => [
          $row['require_line'] ?: '-',                 // Col 1: composer require + ^version (if found)
          $row['label'],                               // Col 2: module name (human)
          $row['machine'],                             // Col 3: name on disk
          $row['origin'],                              // Col 4: composer package/custom/-
          $row['enabled'] ? $this->t('Yes') : $this->t('No'), // Col 5: enabled
        ],
        'class' => $row_classes,
      ];
    }

    $build['intro'] = [
      '#markup' => '<p>' . $this->t('Report excludes Drupal core modules. Rows are highlighted when no module of that Composer package is enabled. Sorted by "composer require" (with version if found) then by Module name. The download provides composer.json-ready lines for packages with at least one enabled module.') . '</p>',
    ];

    // Tiny inline CSS for the yellow rows.
    $build['styles'] = [
      '#type' => 'inline_template',
      '#template' => '<style>.bm-module-report tr.bm-yellow td{background:#fff3cd;}</style>',
    ];

    // Download button as markup.
    $build['download'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['bm-module-report-actions']],
      'button' => ['#markup' => $download_link_markup],
    ];

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('composer require'),
        $this->t('Module name'),
        $this->t('Name on disk'),
        $this->t('Composer package'),
        $this->t('Enabled'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No modules found.'),
      '#attributes' => ['class' => ['bm-module-report']],
    ];

    return $build;
  }

  /**
   * Download the composer.json-ready lines for packages with >= 1 enabled module.
   * Example line: "drupal/admin_toolbar": "^3.6",
   */
  public function download(): Response {
    $extensions = $this->moduleList->reset()->getList();
    $packageMap = $this->buildComposerPackageMap();

    $byPackage = []; // pkg => ['any_enabled' => bool, 'constraint' => '^x.y'|'dev-main'|'*']
    /** @var \Drupal\Core\Extension\Extension $ext */
    foreach ($extensions as $machineName => $ext) {
      if ($this->isCoreModule($ext)) {
        continue;
      }
      [$origin, /* $composerRequireBase */] = $this->detectComposerOrigin($ext, $packageMap);
      $pkg = $this->asComposerPackageOrNull($origin);
      if (!$pkg) {
        continue;
      }
      $enabled = $this->moduleHandler()->moduleExists($machineName);

      if (!isset($byPackage[$pkg]['constraint'])) {
        $pretty = $this->getPrettyVersion($pkg);
        $byPackage[$pkg]['constraint'] = $this->constraintForComposerJson($pretty);
      }
      $byPackage[$pkg]['any_enabled'] = ($byPackage[$pkg]['any_enabled'] ?? false) || $enabled;
    }

    $lines = [];
    foreach ($byPackage as $pkg => $info) {
      if (!empty($info['any_enabled'])) {
        $constraint = $info['constraint'] ?? '*';
        $lines[] = sprintf('"%s": "%s",', $pkg, $constraint);
      }
    }

    $lines = array_values(array_unique($lines));
    sort($lines, SORT_NATURAL | SORT_FLAG_CASE);

    $content = implode("\n", $lines) . "\n";
    $response = new Response($content);
    $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="composer-requires.jsonlines.txt"');
    return $response;
  }

  /* ===================== helpers ===================== */

  private function buildComposerPackageMap(): array {
    $map = [];
    if (class_exists(InstalledVersions::class)) {
      try {
        $packages = InstalledVersions::getInstalledPackagesByType('drupal-module');
        foreach ($packages as $package) {
          $installPath = InstalledVersions::getInstallPath($package);
          if (!$installPath) {
            continue;
          }
          $dir = basename($installPath);
          if ($dir !== '' && $dir !== '.' && $dir !== '..') {
            $map[$dir] = $package; // e.g. 'webform' => 'drupal/webform'
          }
        }
      }
      catch (\Throwable $e) {
        // Ignore; fallback guessing remains.
      }
    }
    return $map;
  }

  private function isCoreModule(Extension $ext): bool {
    return str_starts_with($ext->getPath(), 'core/modules/');
  }

  /**
   * @return array{0:string,1:?string} [originLabel, baseRequireCommand|null]
   */
  private function detectComposerOrigin(Extension $ext, array $packageMap): array {
    $path = $ext->getPath();
    $originLabel = '-';
    $composerRequire = null;

    if ($this->isCoreModule($ext)) {
      return ['core (drupal/core)', null];
    }

    $needle = 'modules' . DIRECTORY_SEPARATOR . 'contrib' . DIRECTORY_SEPARATOR;
    $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $pos = strpos($normalizedPath, $needle);

    if ($pos !== false) {
      $after = substr($normalizedPath, $pos + strlen($needle));
      $parts = explode(DIRECTORY_SEPARATOR, $after);
      $projectRoot = $parts[0] ?? '';

      if ($projectRoot !== '') {
        if (isset($packageMap[$projectRoot])) {
          $originLabel = $packageMap[$projectRoot];
          $composerRequire = 'composer require ' . $originLabel;
          return [$originLabel, $composerRequire];
        }
        $guessed = 'drupal/' . $projectRoot;
        $originLabel = $guessed;
        $composerRequire = 'composer require ' . $guessed;
        return [$originLabel, $composerRequire];
      }
    }

    if (str_contains($normalizedPath, 'modules' . DIRECTORY_SEPARATOR . 'custom' . DIRECTORY_SEPARATOR)) {
      return ['custom', null];
    }

    return [$originLabel, $composerRequire];
  }

  private function asComposerPackageOrNull(string $origin): ?string {
    $origin = trim($origin);
    if ($origin !== '' && $origin !== '-' && $origin !== 'custom' && str_contains($origin, '/')) {
      return $origin;
    }
    return null;
  }

  private function getPrettyVersion(string $package): ?string {
    if (!class_exists(InstalledVersions::class)) {
      return null;
    }
    try {
      return InstalledVersions::getPrettyVersion($package) ?: null;
    }
    catch (\Throwable $e) {
      return null;
    }
  }

  private function toCaretConstraint(string $pretty): string {
    $v = ltrim($pretty, 'vV');
    if (stripos($v, 'dev') !== false) {
      return $v; // keep dev branches literal
    }
    if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $v, $m)) {
      return '^' . $m[1] . '.' . $m[2];
    }
    if (preg_match('/^(\d+)\.(\d+)/', $v, $m)) {
      return '^' . $m[1] . '.' . $m[2];
    }
    if (preg_match('/^(\d+)$/', $v, $m)) {
      return '^' . $m[1] . '.0';
    }
    return $v;
  }

  private function constraintForComposerJson(?string $pretty): string {
    if (!$pretty) {
      return '*';
    }
    $c = $this->toCaretConstraint($pretty);
    return $c !== '' ? $c : '*';
  }

}

<?php

namespace Drupal\openy_traction_rec_import\Drush\Commands;

use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\openy_traction_rec_import\Cleaner;
use Drupal\openy_traction_rec_import\Importer;
use Drupal\openy_traction_rec_import\TractionRecFetcher;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * OPENY Traction Rec import drush commands.
 */
final class OpenyTractionRecImportCommands extends DrushCommands implements SiteAliasManagerAwareInterface {

  use AutowireTrait;
  use SiteAliasManagerAwareTrait;
  use StringTranslationTrait;

  public function __construct(
    #[Autowire(service: 'openy_traction_rec_import.importer')]
    protected Importer $importer,
    #[Autowire(service: 'openy_traction_rec_import.cleaner')]
    protected Cleaner $cleaner,
    #[Autowire(service: 'file_system')]
    protected FileSystemInterface $fileSystem,
    #[Autowire(service: 'entity_type.manager')]
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'openy_traction_rec_import.fetcher')]
    protected TractionRecFetcher $tractionRecFetcher,
    #[Autowire(service: 'database')]
    protected Connection $database,
    #[Autowire(service: 'logger.channel.tr_import')]
    protected LoggerChannelInterface $tractionRecLogChannel,
  ) {
    parent::__construct();
  }

  /**
   * Executes the Traction Rec import.
   *
   * @param array $options
   *   Additional options for the command.
   *
   * @return bool
   *   Execution status.
   *
   * @throws \Exception
   */
  #[CLI\Command(name: 'openy-tr:import', aliases: ['tr:import'])]
  #[CLI\Option(name: 'sync', description: 'Sync source and destination. Delete destination records that do not exist in the source.')]
  #[CLI\Option(name: 'update', description: 'In addition to processing unprocessed items from the source, update previously-imported items with the current data.')]
  public function import(array $options = ['sync' => FALSE, 'update' => FALSE]): bool {
    if (!$this->importer->isEnabled()) {
      $this->tractionRecLogChannel->notice($this->t(
        'The Traction Rec import is not enabled! Enable the
        "openy_traction_rec_import" module, then enable the syncer at @settings.',
        [
          '@settings' =>
            Url::fromRoute(
              'openy_traction_rec_import.settings',
              [],
              ['absolute' => TRUE])->toString(),
        ]
      ));
      return FALSE;
    }

    if (!$this->importer->acquireLock()) {
      $this->tractionRecLogChannel->notice(
        'Can\'t run a new import, another import process is in progress.
        Try "openy-tr:reset-lock" if the process seems stuck.'
      );
      return FALSE;
    }

    if (!$this->importer->checkMigrationsStatus()) {
      $this->tractionRecLogChannel->notice(
        'One or more migrations are still running or stuck. Run
        "drush migrate:status" to see the status of migrations and
        "drush migrate:reset migrationId" to reset the stuck migration.');
      return FALSE;
    }

    $this->output()->writeln('Starting Traction Rec migration.');

    $dirs = $this->importer->getJsonDirectoriesList();
    if (empty($dirs)) {
      $this->tractionRecLogChannel->notice('No Traction Rec data to import.');
      return FALSE;
    }

    foreach ($dirs as $dir) {
      $this->importer->directoryImport($dir, $options);
    }

    $this->importer->releaseLock();
    $this->output()->writeln('Traction Rec migration done!');

    return TRUE;
  }

  /**
   * Executes the Traction Rec rollback.
   */
  #[CLI\Command(name: 'openy-tr:rollback', aliases: ['tr:rollback'])]
  public function rollback() {
    try {
      $this->output()->writeln('Rolling back Traction Rec migrations...');
      $this->processManager()->drush(
        $this->siteAliasManager->getSelf(),
        'migrate:rollback',
        [],
        ['group' => Importer::MIGRATE_GROUP])
        ->run();
      $this->output()->writeln('Rollback done!');
    }
    catch (\Exception $e) {
      $this->tractionRecLogChannel->error($e->getMessage());
    }
  }

  /**
   * Resets the import lock.
   */
  #[CLI\Command(name: 'openy-tr:reset-lock', aliases: ['tr:reset-lock'])]
  public function resetLock() {
    $this->output()->writeln('Reset import status...');
    $this->importer->releaseLock();
  }

  /**
   * Clean up actions.
   *
   * @param array $options
   *   The array of command options.
   */
  #[CLI\Command(name: 'openy-tr:clean-up', aliases: ['tr:clean-up'])]
  public function cleanUp(array $options = []) {
    $this->output()->writeln('Starting clean up...');
    $this->cleaner->cleanBackupFiles();
    $this->output()->writeln('Clean up finished!');
  }

  /**
   * Run Traction Rec fetcher.
   */
  #[CLI\Command(name: 'openy-tr:fetch-all', aliases: ['tr:fetch'])]
  public function fetch() {
    if (!$this->tractionRecFetcher->isEnabled()) {
      $this->tractionRecLogChannel->notice($this->t(
        'The Traction Rec fetcher is not enabled! Enable the fetcher at @settings',
        [
          '@settings' =>
          Url::fromRoute(
              'openy_traction_rec_import.settings',
              [],
              ['absolute' => TRUE])->toString(),
        ]));
      return FALSE;
    }

    $this->tractionRecLogChannel->notice("Fetching data from Traction Rec.");
    $fetch = $this->tractionRecFetcher->fetch();

    if (!is_dir($fetch)) {
      $this->tractionRecLogChannel->warning('Traction Rec data fetch failed. Check the logs for more info.');
    }
    else {
      $this->tractionRecLogChannel->notice("Traction Rec data fetched to " . $fetch);
    }
  }

  /**
   * Run Traction Rec Total Available sync.
   */
  #[CLI\Command(name: 'openy-tr:quick-availability-sync', aliases: ['tr:qas'])]
  public function updateTotalAvailable() {
    if (!$this->tractionRecFetcher->isEnabled()) {
      $this->tractionRecLogChannel->notice($this->t(
        'The Traction Rec fetcher is not enabled! Enable the fetcher at @settings',
        [
          '@settings' =>
            Url::fromRoute(
              'openy_traction_rec_import.settings',
              [],
              ['absolute' => TRUE])->toString(),
        ]));
      return FALSE;
    }

    $count = 0;
    $this->tractionRecLogChannel->notice("Fetching data from Traction Rec.");
    $totalAvailableList = $this->tractionRecFetcher->fetchTotalAvailable();

    $migration_map = $this->database->select('migrate_map_tr_sessions_import', 'm')
      ->fields('m', ['sourceid1', 'destid1'])
      ->execute()
      ->fetchAllKeyed();

    foreach ($totalAvailableList as $sessionId => $totalAvailableItem) {
      if (!empty($migration_map[$sessionId])) {
        $node = $this->entityTypeManager->getStorage('node')->load($migration_map[$sessionId]);
        if ($node instanceof NodeInterface) {
          $totalCapacityAvailable = $totalAvailableItem['Unlimited_Capacity'] ? 100 : max((int) $totalAvailableItem['Total_Capacity_Available'], 0);
          $node->set('field_availability', $totalCapacityAvailable);
          $node->set('waitlist_unlimited_capacity', $totalAvailableItem['Unlimited_Waitlist_Capacity']);
          $node->set('waitlist_capacity', $totalAvailableItem['Waitlist_Capacity']);
          $node->save();
          $count++;
        }
      }
    }
    $this->tractionRecLogChannel->notice($this->t('Total available data were synced for @count sessions', ['@count' => $count]));
  }

}

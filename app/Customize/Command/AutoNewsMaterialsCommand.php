<?php

namespace Customize\Command;

use Customize\Service\AutoNews\AutoNewsConfig;
use Customize\Service\AutoNews\DiscordNotifier;
use Customize\Service\AutoNews\GoogleSheetsClient;
use Customize\Service\AutoNews\MaterialsBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 毎朝7:00：Dots が読む「材料」タブを最新の公開情報で書き換える。
 */
class AutoNewsMaterialsCommand extends Command
{
    protected static $defaultName = 'umashippo:news:materials';

    public function __construct(
        private MaterialsBuilder $builder,
        private GoogleSheetsClient $sheets,
        private DiscordNotifier $discord,
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('お知らせ自動更新：材料タブを更新する')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, '書き込まずに内容だけ表示する')
            ->addOption('init-rules', null, InputOption::VALUE_NONE, 'ルールタブが空なら初期内容を書き込む');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $manual = $this->sheets->getIfExists(MaterialsBuilder::MANUAL_SHEET, 'A2:D');
            if ($manual === null) {
                $output->writeln('「'.MaterialsBuilder::MANUAL_SHEET.'」タブが無いため、手動の材料なしで作ります');
                $manual = [];
            }
            $rows = $this->builder->build($manual);

            if ($input->getOption('dry-run')) {
                foreach ($rows as $row) {
                    $output->writeln(implode(' | ', $row));
                }
                $output->writeln(count($rows).'件（書き込みなし）');

                return Command::SUCCESS;
            }

            // 先に新しい内容を書き、そのあと余った古い行だけを消す（途中で失敗しても空にならない）
            $this->sheets->update(AutoNewsConfig::SHEET_MATERIALS, 'A1:F1', [MaterialsBuilder::HEADER]);
            if ($rows) {
                $this->sheets->update(AutoNewsConfig::SHEET_MATERIALS, 'A2:F'.(count($rows) + 1), $rows);
            }
            $this->sheets->clear(AutoNewsConfig::SHEET_MATERIALS, 'A'.(count($rows) + 2).':F');
            $output->writeln('材料を'.count($rows).'件書き込みました');

            if ($input->getOption('init-rules')) {
                if ($this->sheets->get(AutoNewsConfig::SHEET_RULES, 'A1:B1')) {
                    $output->writeln('ルールタブは入力済みのため変更しません');
                } else {
                    $rules = MaterialsBuilder::defaultRules();
                    $this->sheets->update(AutoNewsConfig::SHEET_RULES, 'A1:B'.count($rules), $rules);
                    $output->writeln('ルールタブに初期内容を書き込みました');
                }
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');
            try {
                $this->discord->send("【お知らせ自動更新】材料の更新に失敗しました。\n".$e->getMessage());
            } catch (\Throwable $ignored) {
            }

            return Command::FAILURE;
        }
    }
}

<?php

namespace PrestaShop\Traces\Command;

use DateTimeImmutable;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateTopQaCommand extends AbstractCommand
{
    private const LABEL_QA = 'QA ✅';

    private const LABEL_QA_COMMUNITY = 'QA by community ✅';

    protected function configure(): void
    {
        $this->setName('traces:generate:topqa')
            ->setDescription('Generate the QA contributors leaderboard from fetched QA label events')
            ->addOption(
                'ghtoken',
                null,
                InputOption::VALUE_OPTIONAL,
                '',
                isset($_ENV['GH_TOKEN']) ? (string) $_ENV['GH_TOKEN'] : null
            )
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, '', 'config.dist.yml');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        if (!file_exists(self::FILE_QA_EVENTS)) {
            $this->output->writeLn(self::FILE_QA_EVENTS . ' is missing. Please execute `php bin/console traces:fetch:qaevents`');

            return 1;
        }

        $this->fetchConfiguration($input->getOption('config'));

        /** @var array{events?: array<array{repo:string, pr_number:int, actor:string, label:string, createdAt:string}>} $payload */
        $payload = json_decode(file_get_contents(self::FILE_QA_EVENTS) ?: '', true) ?: [];
        $events = $payload['events'] ?? [];

        /** @var array<string, mixed> $contributors */
        $contributors = file_exists(self::FILE_CONTRIBUTORS_PRS)
            ? json_decode(file_get_contents(self::FILE_CONTRIBUTORS_PRS) ?: '', true)
            : [];

        file_put_contents(self::FILE_TOP_QA, json_encode($this->buildRanking($events, $contributors), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->output->writeLn(['', 'Top QA generated.']);

        return 0;
    }

    /**
     * @param array<array{repo:string, pr_number:int, actor:string, label:string, createdAt:string}> $events
     * @param array<string, mixed> $contributors
     *
     * @return array{updatedAt: string, items: array<array{rank:int, login:string, name:string, avatar_url:string, html_url:string, count:int, qa:int, qa_community:int}>}
     */
    public function buildRanking(array $events, array $contributors): array
    {
        $counts = [];
        foreach ($events as $ev) {
            $login = $ev['actor'] ?? '';
            $label = $ev['label'] ?? '';
            if ($login === '') {
                continue;
            }
            if (!isset($counts[$login])) {
                $counts[$login] = ['qa' => 0, 'qa_community' => 0];
            }
            if ($label === self::LABEL_QA) {
                ++$counts[$login]['qa'];
            } elseif ($label === self::LABEL_QA_COMMUNITY) {
                ++$counts[$login]['qa_community'];
            }
        }

        $logins = array_keys($counts);
        usort($logins, static function (string $a, string $b) use ($counts): int {
            $totalA = $counts[$a]['qa'] + $counts[$a]['qa_community'];
            $totalB = $counts[$b]['qa'] + $counts[$b]['qa_community'];

            return ($totalB <=> $totalA)
                ?: ($counts[$b]['qa'] <=> $counts[$a]['qa'])
                ?: strcmp($a, $b);
        });

        $items = [];
        $rank = 1;
        foreach ($logins as $login) {
            if (!$this->configKeepExcludedUsers && in_array($login, $this->configExclusions, true)) {
                continue;
            }
            $contributor = isset($contributors[$login]) && is_array($contributors[$login]) ? $contributors[$login] : [];

            if (empty($contributor['avatar_url']) || empty($contributor['name'])) {
                $profile = $this->resolveProfile($login);
                $contributor = array_merge($profile, $contributor);
            }

            $total = $counts[$login]['qa'] + $counts[$login]['qa_community'];
            $items[] = [
                'rank' => $rank,
                'login' => $login,
                'name' => (string) ($contributor['name'] ?? $login),
                'avatar_url' => (string) ($contributor['avatar_url'] ?? ''),
                'html_url' => (string) ($contributor['html_url'] ?? 'https://github.com/' . $login),
                'count' => $total,
                'qa' => $counts[$login]['qa'],
                'qa_community' => $counts[$login]['qa_community'],
            ];
            ++$rank;
        }

        return ['updatedAt' => (new DateTimeImmutable())->format(DATE_ATOM), 'items' => $items];
    }

    /**
     * Resolve a GitHub profile for a login absent from contributors_prs.json
     * (e.g. QA-ers who never contributed code). Cached per-run.
     *
     * @return array{name?: string, avatar_url?: string, html_url?: string}
     */
    private function resolveProfile(string $login): array
    {
        static $cache = [];
        if (isset($cache[$login])) {
            return $cache[$login];
        }
        try {
            $user = $this->github->getUser($login);
        } catch (\Throwable $e) {
            $this->output->writeLn(['  Failed to resolve GitHub user ' . $login . ': ' . $e->getMessage()]);

            return $cache[$login] = [];
        }

        return $cache[$login] = [
            'name' => (string) ($user['name'] ?? $login),
            'avatar_url' => (string) ($user['avatar_url'] ?? ''),
            'html_url' => (string) ($user['html_url'] ?? 'https://github.com/' . $login),
        ];
    }
}

<?php

namespace PrestaShop\Traces\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FetchQaEventsCommand extends AbstractCommand
{
    private const QA_LABELS = ['QA ✅', 'QA by community ✅'];

    protected function configure(): void
    {
        $this->setName('traces:fetch:qaevents')
            ->setDescription('Fetch QA label events from merged PRs across the PrestaShop org')
            ->addOption(
                'ghtoken',
                null,
                InputOption::VALUE_OPTIONAL,
                '',
                isset($_ENV['GH_TOKEN']) ? (string) $_ENV['GH_TOKEN'] : null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $time = time();
        $events = [];

        foreach (self::QA_LABELS as $label) {
            $this->output->writeLn(['', 'Label: ' . $label]);
            $events = array_merge($events, $this->fetchLabelEvents($label));
        }

        $events = $this->dedup($events);

        file_put_contents(self::FILE_QA_EVENTS, json_encode([
            'events' => array_values($events),
            'fetchedAt' => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->output->writeLn(['', count($events) . ' unique QA events written to ' . self::FILE_QA_EVENTS . ' in ' . (time() - $time) . 's.']);

        return 0;
    }

    private function fetchLabelEvents(string $label): array
    {
        $labelEscaped = str_replace('"', '\\"', $label);
        $queryTpl = 'search(query: "label:\"' . $labelEscaped . '\" is:pr is:merged org:PrestaShop", type: ISSUE, first: 100, after: %s) {
            pageInfo { endCursor hasNextPage }
            nodes {
                ... on PullRequest {
                    number
                    repository { name }
                    timelineItems(itemTypes: [LABELED_EVENT], first: 100) {
                        nodes {
                            ... on LabeledEvent {
                                actor { login }
                                label { name }
                                createdAt
                            }
                        }
                    }
                }
            }
        }';

        $events = [];
        $after = 'null';
        do {
            $data = $this->github->apiSearchGraphQL('query { ' . sprintf($queryTpl, $after) . ' }');
            $search = $data['data']['search'] ?? null;
            if ($search === null) {
                $this->output->writeLn(['  GraphQL returned no data for label ' . $label]);
                break;
            }
            foreach ($search['nodes'] as $pr) {
                if (empty($pr['number'])) {
                    continue;
                }
                foreach ($pr['timelineItems']['nodes'] as $ev) {
                    if (($ev['label']['name'] ?? null) !== $label) {
                        continue;
                    }
                    if (empty($ev['actor']['login'])) {
                        continue;
                    }
                    $events[] = [
                        'repo' => $pr['repository']['name'],
                        'pr_number' => $pr['number'],
                        'actor' => $ev['actor']['login'],
                        'label' => $label,
                        'createdAt' => $ev['createdAt'],
                    ];
                }
            }
            $endCursor = $search['pageInfo']['endCursor'] ?? null;
            $after = $endCursor === null ? 'null' : '"' . $endCursor . '"';
            $this->output->writeLn(['  page fetched — running total for label: ' . count($events)]);
        } while (($search['pageInfo']['hasNextPage'] ?? false) === true);

        return $events;
    }

    private function dedup(array $events): array
    {
        $keyed = [];
        foreach ($events as $ev) {
            $key = $ev['repo'] . '#' . $ev['pr_number'] . '@' . $ev['actor'] . '|' . $ev['label'];
            if (!isset($keyed[$key]) || strcmp($ev['createdAt'], $keyed[$key]['createdAt']) < 0) {
                $keyed[$key] = $ev;
            }
        }

        return $keyed;
    }
}

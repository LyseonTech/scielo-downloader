<?php

namespace ScieloScrapping\Command\Ojs;

use Category;
use OjsSdk\Providers\Ojs\OjsProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use DAORegistry;
use DAOResultFactory;
use JournalDAO;
use Publication;
use Section;
use SplFileInfo;
use Submission;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Finder\Finder;

class ImportCommand extends Command
{
    /** @var string */
    protected static $defaultName = 'ojs:import';
    /** @var InputInterface */
    private $input;
    /** @var OutputInterface */
    private $output;
    /** @var string */
    private $outputDirectory;
    /** @var \stdClass */
    private $grid;
    /** @var bool */
    private $doSaveGrid = false;
    /** @var Finder */
    private $metadataFinder;
    /** @var int */
    private $totalMetadata = 0;
    /** @var ProgressBar */
    private $progressBar;
    /** @var Category[] */
    private $category = [];
    /** @var Section[] */
    private $section = [];

    protected function configure()
    {
        $this
            ->setDescription('Import all to OJS')
            ->addOption('ojs-basedir', null, InputOption::VALUE_REQUIRED, 'Base directory of OJS setup', '/app/ojs')
            ->addOption('journal-path', null, InputOption::VALUE_REQUIRED, 'Journal to import')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output directory', 'output')
            ->addOption('copy-category-to-section', null, InputOption::VALUE_NONE, 'Insert all category as section')
            ->addOption('insert-category', null, InputOption::VALUE_NONE, 'Insert category');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $this->loadOjsBasedir();
        OjsProvider::getApplication();

        $this->startProgressBar();

        $this->saveIssues();
        $this->saveSubmission();

        $this->progressBar->setMessage('Done! :-D', 'status');
        $this->progressBar->finish();

        $output->writeln('');
        return Command::SUCCESS;
    }

    private function startProgressBar()
    {
        $this->output->writeln('');
        $this->output->writeln('');
        $this->progressBar = new ProgressBar($this->output);
        $this->progressBar->start();
        $this->progressBar->setMessage('Calculating total itens to import...', 'status');
        $this->progressBar->setFormat(
            "<fg=white;bg=cyan> %status:-45s%</>\n" .
            "\n" .
            "%memory:44s%"
        );
        $this->progressBar->display();
        $this->progressBar->setBarCharacter('<fg=green>⚬</>');
        $this->progressBar->setEmptyBarCharacter("<fg=red>⚬</>");
        $this->progressBar->setProgressCharacter("<fg=green>➤</>");
        $this->progressBar->setFormat(
            "<fg=white;bg=cyan> %status:-45s%</>\n" .
            "%current%/%max% [%bar%] %percent:3s%%\n" .
            "  %estimated:-20s%  %memory:20s%"
        );
        $total = $this->countIssues() + $this->countMetadata();
        $this->progressBar->setMaxSteps($total);
    }

    private function getMetadataFinder(): Finder
    {
        if (!$this->metadataFinder) {
            $this->metadataFinder = Finder::create()
                ->files()
                ->name('metadata_*.json')
                ->in($this->getOutputDirectory());
        }
        return $this->metadataFinder;
    }

    private function countMetadata(): int
    {
        if (!$this->totalMetadata) {
            $finder = $this->getMetadataFinder();
            $this->totalMetadata = $finder->count();
        }
        return $this->totalMetadata;
    }

    private function saveSubmission()
    {
        $finder = $this->getMetadataFinder();
        $total = $this->countMetadata();

        if (!$total) {
            throw new RuntimeException('Metadata json files not found.');
        }
        $this->progressBar->setMessage('Importing submission...', 'status');
        foreach ($finder as $file) {
            $article = file_get_contents($file->getRealPath());
            $article = json_decode($article, true);
            if (!$article) {
                $this->progressBar->advance();
                continue;
            }

            $update = false;
            if (!$article['ojs']['submissionId']) {
                $update = true;
                $submission = $this->insertSubmission($article);
            }

            if (!$article['ojs']['publicationId']) {
                $update = true;
                $publication = $this->insertPublication($file, $article, $submission);
                $insertCategory = $this->input->getOption('insert-category');
                if ($insertCategory) {
                    $this->assignPublicationToCategory($publication, $article['category']);
                }
            }
            if ($update) {
                file_put_contents($file->getRealPath(), json_encode($article));
            }
            $this->progressBar->advance();
        }
    }

    public function insertPublication(SplFileInfo $file, array &$article, ?Submission $submission)
    {
        /**
         * @var SubmissionDAO
         */
        $SubmissionDAO = DAORegistry::getDAO('SubmissionDAO');
        /**
         * @var PublicationDAO
         */
        $PublicationDAO = DAORegistry::getDAO('PublicationDAO');
        $issue = $this->getIssueByFile($file);

        $publication = $PublicationDAO->newDataObject();
        $publication->setData('submissionId', $article['ojs']['submissionId']);
        $publication->setData('status', 1); // published
        $publication->setData('issueId', $issue['issueId']);
        $publication->setData('locale', $this->identifyPrimaryLanguage($article));
        $publication->setData('pub-id::doi', $article['doi']);
        $publication->setData('version', 1);
        $publication->setData('datePublished', $article['published']);
        $publication->setData('lastModified', $article['updated']);
        foreach ($article['title'] as $lang => $title) {
            $publication->setData('title', $title, $lang);
        }
        foreach ($article['resume'] as $lang => $resume) {
            $publication->setData('abstract', $resume, $lang);
        }

        $copyCategoryToSection = $this->input->getOption('copy-category-to-section');
        if ($copyCategoryToSection) {
            $section = $this->getSection($article['category']);
            $publication->setData('sectionId', $section->getId());
        }

        // 'disciplines', 'keywords', 'languages', 'subjects', 'supportingAgencies'
        // categoryIds
        $article['ojs']['publicationId'] = $PublicationDAO->insertObject($publication);

        if (!$submission) {
            $submission = $SubmissionDAO->getById($article['ojs']['submissionId']);
        }
        $submission->setData('currentPublicationId', $article['ojs']['publicationId']);
        $SubmissionDAO->updateObject($submission);
        return $publication;
    }

    private function insertSubmission(array &$article)
    {
        /**
         * @var SubmissionDAO
         */
        $SubmissionDAO = DAORegistry::getDAO('SubmissionDAO');
        /**
         * @var Submission
         */
        $submission = $SubmissionDAO->newDataObject();
        $submission->setData('contextId', 1); // Journal = CSP
        $submission->setData('status', STATUS_PUBLISHED);
        $submission->setData('stageId', WORKFLOW_STAGE_ID_PRODUCTION);
        $submission->setData('dateLastActivity', str_pad($article['updated'], 10, '-01', STR_PAD_RIGHT));
        $submission->setData('dateSubmitted', str_pad($article['published'], 10, '-01', STR_PAD_RIGHT));
        $submission->setData('lastModified', str_pad($article['updated'], 10, '-01', STR_PAD_RIGHT));
        $article['ojs']['submissionId'] = $SubmissionDAO->insertObject($submission);
        return $submission;
    }

    private function getSection($name)
    {
        if (!isset($this->section[$name])) {
            $journal = $this->getJournal();
            $sectionDao = DAORegistry::getDAO('SectionDAO'); /* @var $sectionDao SectionDAO */
            $this->section[$name] = $sectionDao->getByTitle($name, $journal->getId());
            if (!$this->section[$name]) {
                $langs = $journal->getSupportedLocales();
                $this->section[$name] = $sectionDao->newDataObject();
                $this->section[$name]->setContextId($journal->getId());
                foreach ($langs as $lang) {
                    $this->section[$name]->setTitle($name, $lang);
                }
                $sectionDao->insertObject($this->section[$name]);
            }
        }
        return $this->section[$name];
    }

    private function assignPublicationToCategory(Publication $publication, string $categoryName)
    {
        $categoryDao = DAORegistry::getDAO('CategoryDAO'); /* @var $categoryDao CategoryDAO */
        $category = $this->getCategory($categoryName);
        $categoryDao->insertPublicationAssignment($category->getId(), $publication->getId());
    }

    private function getCategory($name)
    {
        if (!isset($this->category[$name])) {
            $journal = $this->getJournal();
            $categoryDao = DAORegistry::getDAO('CategoryDAO'); /* @var $categoryDao CategoryDAO */
            $this->category[$name] = $categoryDao->getByTitle($name, $journal->getId());
            if (!$this->category[$name]) {
                $langs = $journal->getSupportedLocales();
                $category = $categoryDao->newDataObject();
                $category->setContextId($journal->getId());
                $category->setPath($name);
                foreach ($langs as $lang) {
                    $category->setTitle($name, $lang);
                }
                $categoryDao->insertObject($category);
            }
        }
        return $this->category[$name];
    }

    private function getIssueByFile(SplFileInfo $file)
    {
        $path = $file->getRelativePath();
        $path = preg_replace('/'.$this->getOutputDirectory().'/', '', $path, 1);
        $path = trim($path, '/');
        list($year, $volume, $issueName) = explode('/', $path);
        return $this->getGrid()[$year][$volume][$issueName];
    }

    private function saveIssues()
    {
        $journal = $this->getJournal();
        $langs = $journal->getSupportedLocales();
        /**
         * @var IssueDAO
         */
        $issueDAO = DAORegistry::getDAO('IssueDAO');

        $total = $this->countIssues();
        if (!$total) {
            throw new RuntimeException('No issues to import.');
        }

        $this->progressBar->setMessage('Importing issues...', 'status');
        $grid = $this->getGrid();
        foreach ($grid as $year => $volumes) {
            foreach ($volumes as $volume => $issues) {
                foreach ($issues as $issueName => $attr) {
                    if (isset($attr['issueId'])) {
                        $this->progressBar->advance();
                        continue;
                    }
                    $issues = $this->getIssueFromDb($journal->getId(), $volume, $year, $attr['text']);
                    if ($issues->getCount()) {
                        $issue = $issues->next();
                        $this->setGridAttribute($issue->getYear(), $issue->getvolume(), $issueName, 'issueId', $issue->getId());
                        $this->progressBar->advance();
                        continue;
                    }
                    // Insert issue
                    $issue = $issueDAO->newDataObject();
                    $issue->setJournalId($journal->getId());
                    $issue->setVolume($volume);
                    $issue->setShowVolume(1);
                    $issue->setNumber($attr['text']);
                    $issue->setShowNumber(1);
                    $issue->setYear($year);
                    $issue->setShowYear(1);
                    foreach ($langs as $lang) {
                        $issue->setTitle($attr['text'], $lang);
                    }
                    $issue->setShowTitle(1);
                    $issue->setPublished(1);
                    $issueId = $issueDAO->insertObject($issue);
                    $this->setGridAttribute($year, $volume, $issueName, 'issueId', $issueId);
                    $this->progressBar->advance();
                }
            }
        }
        $this->saveGrid();
    }

    private function getIssueFromDb($journalId, $volume, $year, $title)
    {
        /**
         * @var IssueDAO
         */
        $issueDAO = DAORegistry::getDAO('IssueDAO');

        $sqlTitleJoin = ' LEFT JOIN issue_settings iss1 ON (i.issue_id = iss1.issue_id AND iss1.setting_name = \'title\')';
        $params[] = (int) $journalId;
        $params[] = (int) $volume;
        $params[] = (int) $year;
        $params[] = str_replace('.', '%', strtolower($title));
        $params[] = str_replace('.', '%', strtolower($title));
        $sql =
            'SELECT i.*
            FROM issues i'
            . $sqlTitleJoin
            . ' WHERE i.journal_id = ?'
            . ' AND i.volume = ?'
            . ' AND i.year = ?'
            . ' AND (LOWER(i.number) LIKE ? OR LOWER(iss1.setting_value) LIKE ?)';
        $result = $issueDAO->retrieve($sql, $params);
        return new DAOResultFactory($result, $issueDAO, '_returnIssueFromRow');
    }

    /**
     * Count issues
     *
     * @return integer
     */
    private function countIssues(): int
    {
        $total = 0;
        $grid = $this->getGrid();
        foreach ($grid as $volumes) {
            foreach ($volumes as $issues) {
                $total += count($issues);
            }
        }
        return $total;
    }

    private function setGridAttribute($year, $volume, $issueName, $attribute, $value)
    {
        $this->doUpgradeGrid = true;
        $this->grid[$year][$volume][$issueName][$attribute] = $value;
    }

    private function loadOjsBasedir()
    {
        $ojsBasedir = $this->input->getOption('ojs-basedir');
        if (!is_dir($ojsBasedir)) {
            $ojsBasedir = getenv('OJS_WEB_BASEDIR');
            if (!$ojsBasedir) {
                throw new RuntimeException('Inform a valid path in ojs-basedir option');
            }
        }
        putenv('OJS_WEB_BASEDIR=' . $ojsBasedir);
    }

    private function getOutputDirectory()
    {
        if (!$this->outputDirectory) {
            $this->outputDirectory = $this->input->getOption('output');
            if (!is_dir($this->outputDirectory)) {
                $this->output->writeln('Run frist scielo download command or fix directory path');
                throw new RuntimeException('Error on create output directory called [' . $this->outputDirectory . ']');
            }
        }
        return $this->outputDirectory;
    }

    private function getGrid()
    {
        if (!$this->grid) {
            $outputDirectory = $this->getOutputDirectory();
            if (!is_file($outputDirectory . '/grid.json')) {
                throw new RuntimeException('grid.json not found');
            }
            $this->grid = file_get_contents($outputDirectory . '/grid.json');
            $this->grid = json_decode($this->grid, true);
            if (!$this->grid) {
                throw new RuntimeException('Invalid content in grid.json</error>');
            }
        }
        return $this->grid;
    }

    private function getJournal()
    {
        /**
         * @var JournalDAO
         */
        $JournalDAO = DAORegistry::getDAO('JournalDAO');
        $journals = $JournalDAO->getAll();
        if (!$journals) {
            throw new RuntimeException('Create a journal in OJS first');
        }
        while ($journal = $journals->next()) {
            $options[$journal->getPath()] = $journal;
        }
        $journalPath = $this->input->getOption('journal-path');
        if ($journalPath) {
            if (!isset($options[$journalPath])) {
                throw new RuntimeException('Invalid option: journal-path not found in OJS');
            }
            return $options[$journalPath];
        }

        if (count($options) > 1) {
            $helper = $this->getHelper('question');
            $question = new ChoiceQuestion(
                'Select the destination journal',
                array_keys($options)
            );
            $question->setErrorMessage('Journal path %s is invalid.');
            $journalPath = $helper->ask($this->input, $this->output, $question);
            return $journals[array_keys($options)[$journalPath]];
        }
        return current($options);
    }

    private function identifyPrimaryLanguage($article)
    {
        if (isset($article['formats']['text'])) {
            if (count($article['formats']['text']) == 1) {
                return array_key_first($article['formats']['text']);
            }
        }
        if (isset($article['formats']['pdf'])) {
            if (count($article['formats']['pdf']) == 1) {
                return array_key_first($article['formats']['pdf']);
            }
        }
        if (isset($article['title'])) {
            if (count($article['title']) == 1) {
                return array_key_first($article['title']);
            }
        }
        if (isset($article['keywords'])) {
            if (count($article['keywords']) == 1) {
                return array_key_first($article['keywords']);
            }
        }
    }

    private function saveGrid()
    {
        if ($this->doSaveGrid) {
            file_put_contents($this->getOutputDirectory() . '/grid.json', json_encode($this->getGrid()));
        }
    }
}

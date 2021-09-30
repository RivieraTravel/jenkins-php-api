<?php

namespace JenkinsKhan;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;


class Jenkins
{

    /**
     * @var string
     */
    private $baseUrl;

    private $client;

    /**
     * @var null
     */
    private $jenkins = null;

    /**
     * Whether or not to retrieve and send anti-CSRF crumb tokens
     * with each request
     *
     * Defaults to false for backwards compatibility
     *
     * @var boolean
     */
    private $crumbsEnabled = false;

    /**
     * The anti-CSRF crumb to use for each request
     *
     * Set when crumbs are enabled, by requesting a new crumb from Jenkins
     *
     * @var string
     */
    private $crumb;

    /**
     * The header to use for sending anti-CSRF crumbs
     *
     * Set when crumbs are enabled, by requesting a new crumb from Jenkins
     *
     * @var string
     */
    private $crumbRequestField;

    /**
     * @param string $baseUrl
     */
    public function __construct(ClientInterface $client, $baseUrl)
    {
        $this->client = $client;
        $this->baseUrl = $baseUrl;
    }


    protected function createHttpClientOption()
    {
        $options = [];

        return $options;
    }

    /**
     * Enable the use of anti-CSRF crumbs on requests
     *
     * @return void
     */
    public function enableCrumbs()
    {
        $this->crumbsEnabled = true;

        $crumbResult = $this->requestCrumb();

        if (!$crumbResult || !is_object($crumbResult)) {
            $this->crumbsEnabled = false;

            return;
        }

        $this->crumb             = $crumbResult->crumb;
        $this->crumbRequestField = $crumbResult->crumbRequestField;
    }

    /**
     * Disable the use of anti-CSRF crumbs on requests
     *
     * @return void
     */
    public function disableCrumbs()
    {
        $this->crumbsEnabled = false;
    }

    /**
     * Get the status of anti-CSRF crumbs
     *
     * @return boolean Whether or not crumbs have been enabled
     */
    public function areCrumbsEnabled()
    {
        return $this->crumbsEnabled;
    }

    public function requestCrumb()
    {
        $url = sprintf('%s/crumbIssuer/api/json', $this->baseUrl);

        $request = new Request(
            'GET',
            $url
        );

        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse($response, 'Error getting csrf crumb');

        $crumbResult = json_decode($response->getBody());

        if (!$crumbResult instanceof \stdClass) {
            throw new \RuntimeException('Error during json_decode of csrf crumb');
        }

        return $crumbResult;
    }

    public function getCrumbHeader()
    {
        return "$this->crumbRequestField: $this->crumb";
    }

    /**
     * @return boolean
     */
    public function isAvailable()
    {
        $request = new Request(
            'GET',
            $this->baseUrl . '/api/json'
        );

        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse($response, 'Error getting csrf crumb');

        $crumbResult = json_decode($response->getBody());

        $statusCode = $response->getStatusCode();

        if (200 !== $statusCode) {
            return false;
        } else {
            try {
                $this->getQueue();
            } catch (RuntimeException $e) {
                //en cours de lancement de jenkins, on devrait passer par là
                return false;
            }
        }

        return true;
    }

    /**
     * @return void
     * @throws \RuntimeException
     */
    private function initialize()
    {
        if (null !== $this->jenkins) {
            return;
        }

        $request = new Request(
            'GET',
            $this->baseUrl . '/api/json'
        );

        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse($response, sprintf('Error during getting list of jobs on %s', $this->baseUrl));

        $this->jenkins = json_decode($response->getBody());
        if (!$this->jenkins instanceof \stdClass) {
            throw new \RuntimeException('Error during json_decode');
        }
    }

    /**
     * @throws \RuntimeException
     * @return array
     */
    public function getAllJobs()
    {
        $this->initialize();

        $jobs = array();
        foreach ($this->jenkins->jobs as $job) {
            $jobs[$job->name] = array(
                'name' => $job->name
            );
        }

        return $jobs;
    }

    /**
     * @return Jenkins\Job[]
     */
    public function getJobs()
    {
        $this->initialize();

        $jobs = array();
        foreach ($this->jenkins->jobs as $job) {
            $jobs[$job->name] = $this->getJob($job->name);
        }

        return $jobs;
    }

    /**
     * @param string $computer
     *
     * @return array
     * @throws \RuntimeException
     */
    public function getExecutors($computer = '(master)')
    {
        $this->initialize();

        $executors = array();
        for ($i = 0; $i < $this->jenkins->numExecutors; $i++) {
            $url  = sprintf('%s/computer/%s/executors/%s/api/json', $this->baseUrl, $computer, $i);
            $request = new Request(
                'GET',
                $url
            );

            $options = $this->createHttpClientOption();

            $response = $this->client->send($request, $options);

            $this->validateResponse(
                $response,
                sprintf( 'Error during getting information for executors[%s@%s] on %s', $i, $computer, $this->baseUrl)
            );

            $infos = json_decode($response->getBody());
            if (!$infos instanceof \stdClass) {
                throw new \RuntimeException('Error during json_decode');
            }

            $executors[] = new Jenkins\Executor($infos, $computer, $this);
        }

        return $executors;
    }

    /**
     * @param       $jobName
     * @param array $parameters
     *
     * @return bool
     * @internal param array $extraParameters
     *
     */
    public function launchJob($jobName, $parameters = array())
    {
        if (0 === count($parameters)) {
            $url = sprintf('%s/job/%s/build', $this->baseUrl, $jobName);
        } else {
            $url = sprintf('%s/job/%s/buildWithParameters', $this->baseUrl, $jobName);
        }

        $headers = array();

        if ($this->areCrumbsEnabled()) {
            $headers[] = $this->getCrumbHeader();
        }


        $request = new Request(
            'POST',
            $url,
            $headers,
            http_build_query($parameters)
        );



        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse(
            $response,
            sprintf('Error trying to launch job "%s" (%s)', $jobName, $url)
        );

        return true;
    }

    /**
     * @param string $jobName
     *
     * @return bool|\JenkinsKhan\Jenkins\Job
     * @throws \RuntimeException
     */
    public function getJob($jobName)
    {
        $url  = sprintf('%s/job/%s/api/json', $this->baseUrl, $jobName);
        $request = new Request(
            'GET',
            $url
        );



        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse(
            $response,
            sprintf('Error during getting information for job %s on %s', $jobName, $this->baseUrl)
        );

        $infos = json_decode($response->getBody());

        $statusCode = $response->getStatusCode();

        if (200 !== $statusCode) {
            return false;
        }

        if (!$infos instanceof \stdClass) {
            throw new \RuntimeException('Error during json_decode');
        }

        return new Jenkins\Job($infos, $this);
    }

    /**
     * @param string $jobName
     *
     * @return void
     */
    public function deleteJob($jobName)
    {
        $url  = sprintf('%s/job/%s/doDelete', $this->baseUrl, $jobName);

      $headers = array();

        if ($this->areCrumbsEnabled()) {
            $headers[] = $this->getCrumbHeader();
        }


        $request = new Request(
            'POST',
            $url,
            $headers
        );

        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse(
            $response,
            sprintf('Error deleting job %s on %s', $jobName, $this->baseUrl)
        );
    }

    /**
     * @return Jenkins\Queue
     * @throws \RuntimeException
     */
    public function getQueue()
    {
        $url  = sprintf('%s/queue/api/json', $this->baseUrl);
        $request = new Request(
            'GET',
            $url
        );

        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse(
            $response,
            sprintf('Error during getting information for queue on %s', $this->baseUrl)
        );

        $infos = json_decode($response->getBody());

        if (!$infos instanceof \stdClass) {
            throw new \RuntimeException('Error during json_decode');
        }

        return new Jenkins\Queue($infos, $this);
    }

    /**
     * @return Jenkins\View[]
     */
    public function getViews()
    {
        $this->initialize();

        $views = array();
        foreach ($this->jenkins->views as $view) {
            $views[] = $this->getView($view->name);
        }

        return $views;
    }

    /**
     * @return Jenkins\View|null
     */
    public function getPrimaryView()
    {
        $this->initialize();
        $primaryView = null;

        if (property_exists($this->jenkins, 'primaryView')) {
            $primaryView = $this->getView($this->jenkins->primaryView->name);
        }

        return $primaryView;
    }


    /**
     * @param string $viewName
     *
     * @return Jenkins\View
     * @throws \RuntimeException
     */
    public function getView($viewName)
    {
        $url  = sprintf('%s/view/%s/api/json', $this->baseUrl, rawurlencode($viewName));
        $request = new Request(
            'GET',
            $url
        );

        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse(
            $response,
            sprintf('Error during getting information for view %s on %s', $viewName, $this->baseUrl)
        );

        $infos = json_decode($response->getBody());

        if (!$infos instanceof \stdClass) {
            throw new \RuntimeException('Error during json_decode');
        }

        return new Jenkins\View($infos, $this);
    }


    /**
     * @param        $job
     * @param        $buildId
     * @param string $tree
     *
     * @return Jenkins\Build
     * @throws \RuntimeException
     */
    public function getBuild($job, $buildId, $tree = 'actions[parameters,parameters[name,value]],result,duration,timestamp,number,url,estimatedDuration,builtOn')
    {
        if ($tree !== null) {
            $tree = sprintf('?tree=%s', $tree);
        }
        $url  = sprintf('%s/job/%s/%d/api/json%s', $this->baseUrl, $job, $buildId, $tree);
        $request = new Request(
            'GET',
            $url
        );

        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse(
            $response,
            sprintf('Error during getting information for build %s#%d on %s', $job, $buildId, $this->baseUrl)
        );

        $infos = json_decode($response->getBody());
        if (!$infos instanceof \stdClass) {
            return null;
        }

        return new Jenkins\Build($infos, $this);
    }

    /**
     * @param string $job
     * @param int    $buildId
     *
     * @return null|string
     */
    public function getUrlBuild($job, $buildId)
    {
        return (null === $buildId) ?
            $this->getUrlJob($job)
            : sprintf('%s/job/%s/%d', $this->baseUrl, $job, $buildId);
    }

    /**
     * @param string $computerName
     *
     * @return Jenkins\Computer
     * @throws \RuntimeException
     */
    public function getComputer($computerName)
    {
        $url  = sprintf('%s/computer/%s/api/json', $this->baseUrl, $computerName);
        $request = new Request(
            'GET',
            $url
        );

        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse(
            $response,
            sprintf('Error during getting information for computer %s on %s', $computerName, $this->baseUrl)
        );

        $infos = json_decode($response->getBody());

        if (!$infos instanceof \stdClass) {
            return null;
        }

        return new Jenkins\Computer($infos, $this);
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        return $this->baseUrl;
    }

    /**
     * @param string $job
     *
     * @return string
     */
    public function getUrlJob($job)
    {
        return sprintf('%s/job/%s', $this->baseUrl, $job);
    }

    /**
     * getUrlView
     *
     * @param string $view
     *
     * @return string
     */
    public function getUrlView($view)
    {
        return sprintf('%s/view/%s', $this->baseUrl, $view);
    }

    /**
     * @param string $jobname
     *
     * @return string
     *
     * @deprecated use getJobConfig instead
     *
     * @throws \RuntimeException
     */
    public function retrieveXmlConfigAsString($jobname)
    {
        return $this->getJobConfig($jobname);
    }

    /**
     * @param string       $jobname
     * @param \DomDocument $document
     *
     * @deprecated use setJobConfig instead
     */
    public function setConfigFromDomDocument($jobname, \DomDocument $document)
    {
        $this->setJobConfig($jobname, $document->saveXML());
    }

    /**
     * @param string $jobname
     * @param string $xmlConfiguration
     *
     * @throws \InvalidArgumentException
     */
    public function createJob($jobname, $xmlConfiguration)
    {
        $url  = sprintf('%s/createItem?name=%s', $this->baseUrl, $jobname);

        $headers = array('Content-Type: text/xml');

        if ($this->areCrumbsEnabled()) {
            $headers[] = $this->getCrumbHeader();
        }

        $request = new Request(
            'POST',
            $url,
            $headers,
            $xmlConfiguration
        );

        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse(
            $response,
            sprintf('Error creating job %s', $jobname)
        );


        $statusCode = $response->getStatusCode();

        if (200 !== $statusCode) {
            throw new \InvalidArgumentException(sprintf('Job %s already exists', $jobname));
        }
    }

    /**
     * @param string $jobname
     * @param        $configuration
     *
     * @internal param string $document
     */
    public function setJobConfig($jobname, $configuration)
    {
        $url  = sprintf('%s/job/%s/config.xml', $this->baseUrl, $jobname);
        $headers = array('Content-Type: text/xml');

        if ($this->areCrumbsEnabled()) {
            $headers[] = $this->getCrumbHeader();
        }

        $request = new Request(
            'POST',
            $url,
            $headers,
            $xmlConfiguration
        );

        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse(
            $response,
            sprintf('Error during setting configuration for job %s', $jobname)
        );
    }

    /**
     * @param string $jobname
     *
     * @return string
     */
    public function getJobConfig($jobname)
    {
        $url  = sprintf('%s/job/%s/config.xml', $this->baseUrl, $jobname);
        $request = new Request(
            'GET',
            $url
        );

        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse(
            $response,
            sprintf('Error during getting configuration for job %s', $jobname)
        );

        return $response->getBody();
    }

    /**
     * @param Jenkins\Executor $executor
     *
     * @throws \RuntimeException
     */
    public function stopExecutor(Jenkins\Executor $executor)
    {
        $url = sprintf(
            '%s/computer/%s/executors/%s/stop', $this->baseUrl, $executor->getComputer(), $executor->getNumber()
        );
        $headers = array();

        if ($this->areCrumbsEnabled()) {
            $headers[] = $this->getCrumbHeader();
        }

        $request = new Request(
            'POST',
            $url,
            $headers
        );

        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse(
            $response,
            sprintf('Error during stopping executor #%s', $executor->getNumber())
        );
    }

    /**
     * @param Jenkins\JobQueue $queue
     *
     * @throws \RuntimeException
     * @return void
     */
    public function cancelQueue(Jenkins\JobQueue $queue)
    {
        $url = sprintf('%s/queue/item/%s/cancelQueue', $this->baseUrl, $queue->getId());
        $headers = array();

        if ($this->areCrumbsEnabled()) {
            $headers[] = $this->getCrumbHeader();
        }

        $request = new Request(
            'POST',
            $url,
            $headers
        );

        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse(
            $response,
            sprintf('Error during stopping job queue #%s', $queue->getId())
        );
    }

    /**
     * @param string $computerName
     *
     * @throws \RuntimeException
     * @return void
     */
    public function toggleOfflineComputer($computerName)
    {
        $url  = sprintf('%s/computer/%s/toggleOffline', $this->baseUrl, $computerName);
        $headers = array();

        if ($this->areCrumbsEnabled()) {
            $headers[] = $this->getCrumbHeader();
        }

        $request = new Request(
            'POST',
            $url,
            $headers
        );

        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse(
            $response,
            sprintf('Error marking %s offline', $computerName)
        );
    }

    /**
     * @param string $computerName
     *
     * @throws \RuntimeException
     * @return void
     */
    public function deleteComputer($computerName)
    {
        $url  = sprintf('%s/computer/%s/doDelete', $this->baseUrl, $computerName);
        $headers = array();

        if ($this->areCrumbsEnabled()) {
            $headers[] = $this->getCrumbHeader();
        }

        $request = new Request(
            'POST',
            $url,
            $headers
        );



        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse(
            $response,
             sprintf('Error deleting %s', $computerName)
        );
    }

    /**
     * @param string $jobname
     * @param string $buildNumber
     *
     * @return string
     */
    public function getConsoleTextBuild($jobname, $buildNumber)
    {
        $url  = sprintf('%s/job/%s/%s/consoleText', $this->baseUrl, $jobname, $buildNumber);
        $request = new Request(
            'GET',
            $url
        );

        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse(
            $response,
            sprintf('Error during getting console text for job %s', $jobname)
        );
        return $response->getBody();
    }

    /**
     * @param string $jobName
     * @param        $buildId
     *
     * @return array
     * @internal param string $buildNumber
     *
     */
    public function getTestReport($jobName, $buildId)
    {
        $url  = sprintf('%s/job/%s/%d/testReport/api/json', $this->baseUrl, $jobName, $buildId);
        $request = new Request(
            'GET',
            $url
        );

        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse(
            $response,
            sprintf('Error during getting information for build %s#%d on %s', $jobName, $buildId, $this->baseUrl)
        );

        $infos = json_decode($response->getBody());
        if (!$infos instanceof \stdClass) {
            throw new \RuntimeException($errorMessage);
        }

        return new Jenkins\TestReport($this, $infos, $jobName, $buildId);
    }

    /**
     * Returns the content of a page according to the jenkins base url.
     * Useful if you use jenkins plugins that provides specific APIs.
     * (e.g. "/cloud/ec2-us-east-1/provision")
     *
     * @param string $uri
     * @param array  $curlOptions
     *
     * @return string
     */
    public function execute($uri, array $curlOptions)
    {
        $url  = $this->baseUrl . '/' . $uri;


        $request = new Request(
            'GET',
            $url
        );

        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse(
            $response,
            sprintf('Error calling "%s"', $url)
        );
        return $response->getBody();
    }

    /**
     * @return Jenkins\Computer[]
     */
    public function getComputers()
    {
        $return = $this->execute(
            '/computer/api/json', array(
                \CURLOPT_RETURNTRANSFER => 1,
            )
        );
        $infos  = json_decode($return);
        if (!$infos instanceof \stdClass) {
            throw new \RuntimeException('Error during json_decode');
        }
        $computers = array();
        foreach ($infos->computer as $computer) {
            $computers[] = $this->getComputer($computer->displayName);
        }

        return $computers;
    }

    /**
     * @param string $computerName
     *
     * @return string
     */
    public function getComputerConfiguration($computerName)
    {
        return $this->execute(sprintf('/computer/%s/config.xml', $computerName), array(\CURLOPT_RETURNTRANSFER => 1,));
    }

    private function validateResponse(ResponseInterface $response, $errorMessage) {
        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode > 399) {
            throw new \RuntimeException($errorMessage);
        }
    }


}

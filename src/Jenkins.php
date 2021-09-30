<?php

namespace JenkinsKhan;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;


class Jenkins
{
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
    public function __construct(ClientInterface $client)
    {
        $this->client = $client;
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
        $url = 'crumbIssuer/api/json';

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
            'api/json'
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
            'api/json'
        );

        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse($response, sprintf('Error during getting list of jobs on %s', $this->getUrl()));

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
            $url  = sprintf('computer/%s/executors/%s/api/json', $computer, $i);
            $request = new Request(
                'GET',
                $url
            );

            $options = $this->createHttpClientOption();

            $response = $this->client->send($request, $options);

            $this->validateResponse(
                $response,
                sprintf( 'Error during getting information for executors[%s@%s] on %s', $i, $computer, $this->getUrl())
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
            $url = sprintf('job/%s/build', $jobName);
        } else {
            $url = sprintf('job/%s/buildWithParameters', $jobName);
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
        $url  = sprintf('job/%s/api/json', $jobName);
        $request = new Request(
            'GET',
            $url
        );



        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse(
            $response,
            sprintf('Error during getting information for job %s on %s', $jobName, $this->getUrl())
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
        $url  = sprintf('job/%s/doDelete', $jobName);

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
            sprintf('Error deleting job %s on %s', $jobName, $this->getUrl())
        );
    }

    /**
     * @return Jenkins\Queue
     * @throws \RuntimeException
     */
    public function getQueue()
    {
        $url  = 'queue/api/json';
        $request = new Request(
            'GET',
            $url
        );

        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse(
            $response,
            sprintf('Error during getting information for queue on %s', $this->getUrl())
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
        $url  = sprintf('view/%s/api/json', rawurlencode($viewName));
        $request = new Request(
            'GET',
            $url
        );

        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse(
            $response,
            sprintf('Error during getting information for view %s on %s', $viewName, $this->getUrl())
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
        $url  = sprintf('job/%s/%d/api/json%s', $job, $buildId, $tree);
        $request = new Request(
            'GET',
            $url
        );

        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse(
            $response,
            sprintf('Error during getting information for build %s#%d on %s', $job, $buildId, $this->getUrl())
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
            : sprintf('/job/%s/%d', $job, $buildId);
    }

    /**
     * @param string $computerName
     *
     * @return Jenkins\Computer
     * @throws \RuntimeException
     */
    public function getComputer($computerName)
    {
        $url  = sprintf('computer/%s/api/json',  $computerName);
        $request = new Request(
            'GET',
            $url
        );

        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse(
            $response,
            sprintf('Error during getting information for computer %s on %s', $computerName, $this->getUrl())
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
        return $this->client->getConfig()['base_uri'];
    }

    /**
     * @param string $job
     *
     * @return string
     */
    public function getUrlJob($job)
    {
        return sprintf('/job/%s', $job);
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
        return sprintf('/view/%s', $view);
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
        $url  = sprintf('createItem?name=%s', $jobname);

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
        $url  = sprintf('job/%s/config.xml', $jobname);
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
        $url  = sprintf('job/%s/config.xml', $jobname);
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
            'computer/%s/executors/%s/stop', $executor->getComputer(), $executor->getNumber()
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
        $url = sprintf('queue/item/%s/cancelQueue', $queue->getId());
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
        $url  = sprintf('computer/%s/toggleOffline', $computerName);
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
        $url  = sprintf('computer/%s/doDelete', $computerName);
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
        $url  = sprintf('job/%s/%s/consoleText', $jobname, $buildNumber);
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
        $url  = sprintf('job/%s/%d/testReport/api/json', $jobName, $buildId);
        $request = new Request(
            'GET',
            $url
        );

        $options = $this->createHttpClientOption();

        $response = $this->client->send($request, $options);

        $this->validateResponse(
            $response,
            sprintf('Error during getting information for build %s#%d on %s', $jobName, $buildId, $this->getUrl())
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
    public function execute($uri)
    {
        $request = new Request(
            'GET',
            $uri
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
            'computer/api/json'
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
        return $this->execute(sprintf('computer/%s/config.xml', $computerName));
    }

    private function validateResponse(ResponseInterface $response, $errorMessage) {
        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode > 399) {
            throw new \RuntimeException($errorMessage);
        }
    }


}

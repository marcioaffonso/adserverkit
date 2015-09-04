<?php
/* ------------------------------------------------------------------------------------------------
 * Composer Autoloader
 * -----------------------------------------------------------------------------------------------*/
require_once __DIR__.'/../vendor/autoload.php';

use \Slim\Slim;
use \OpenTok\OpenTok;
use \werx\Config\Providers\ArrayProvider;
use \werx\Config\Container;
use \OpenTok\Role;

/* ------------------------------------------------------------------------------------------------
 * Slim Application Initialization
 * -----------------------------------------------------------------------------------------------*/
$app = new Slim(array(
    'log.enabled' => true,
    'templates.path' => '../templates'
));

/* ------------------------------------------------------------------------------------------------
 * Configuration
 * -----------------------------------------------------------------------------------------------*/
$provider = new ArrayProvider('../config');
$config = new Container($provider);

// Environment Selection
$app->configureMode('development', function () use ($config) {
    $config->setEnvironment('development');
});

$config->load(array('opentok', 'mysql'), true);

/* ------------------------------------------------------------------------------------------------
 * OpenTok Initialization
 * -----------------------------------------------------------------------------------------------*/
$opentok = new OpenTok($config->opentok('key'), $config->opentok('secret'));


/* ------------------------------------------------------------------------------------------------
 * Setup MySQL
 * -----------------------------------------------------------------------------------------------*/
// mysql - replace user/pw and database name
// Set env vars in /Applications/MAMP/Library/bin/envvars if you are using MAMP
// MYSQL env: export CLEARDB_DATABASE_URL="mysql://root:root@localhost/adserverkit
// MYSQL formate: username:pw@url/database
$mysql_url = parse_url(getenv("CLEARDB_DATABASE_URL") ? : $config->mysql('mysql_url'));
$dbname = substr($mysql_url['path'], 1);
$host = $mysql_url['host'];
if ($mysql_url['port']) {
    $host = $host . ':' . $mysql_url['port'];
}
$con = mysqli_connect($host, $mysql_url['user'], $mysql_url['pass']);

// Check connection
if (mysqli_connect_errno()) {
    echo "Failed to connect to MySQL: " . mysqli_connect_error();
}

// Create database - only do once if db does not exist
// Use our database and create table
$sql = "CREATE DATABASE IF NOT EXISTS $dbname";
if (!mysqli_query($con, $sql)) {
    echo "Error creating database: " . mysqli_error($con);
}

mysqli_select_db($con, $dbname);

$sql = "CREATE TABLE IF NOT EXISTS `Sessions` (
    `CampaignId` VARCHAR(255),
    `BannerId` VARCHAR(255),
    `UserIpAddress` VARCHAR(255),
    `UserAgent` VARCHAR(255),
    `UserCountry` VARCHAR(255),
    `QueueEntryTime` DATETIME,
    `ConversationStartTime` DATETIME,
    `SessionEndTime` DATETIME,
    `RepresentativeName` VARCHAR(255),
    `Sessionid` VARCHAR(255)
)";
if (!mysqli_query($con, $sql)) {
    echo "Error creating table: " . mysqli_error($con);
}

function sendQuery($query) {
    global $con;
    $result = mysqli_query($con, $query);
    if (!$result) {
        return false;
    }
    return $result;
}

/* ------------------------------------------------------------------------------------------------
 * Routing
 * -----------------------------------------------------------------------------------------------*/

// Customer landing page
$app->get('/', function () use ($app) {
    $app->render('customer.php');
});

// Representative landing page
$app->get('/rep', function () use ($app) {
    $app->render('representative.php');
});

// Customer requests service
//
// A) Create a help session
//    Request: (URL encoded)
//    Response: (JSON encoded)
//    *  `apiKey`: OpenTok API Key
//    *  `sessionId`: OpenTok session ID
//    *  `token`: User's token for the `sessionId`
$app->post('/help/session', function () use ($app, $con, $opentok, $config) {

    $session = $opentok->createSession();
    $responseData = array(
        'apiKey' => $config->opentok('key'),
        'sessionId' => $session->getSessionId(),
        'token' => $session->generateToken(
            array(
                'role'       => Role::MODERATOR,
                'expireTime' => time()+(7 * 24 * 60 * 60), // in one week
                'data'       => 'name=Hans Großer'
            )
        )
    );

    $campaignId = $app->request->params('campaignId');
    $bannerId = $app->request->params('bannerId');
    $userAgent = $app->request->params('userAgent');
    $userIpAddress = get_user_ip_address();
    $country = get_user_country($userIpAddress);
    
    // Save the help session details
    $query = sprintf("INSERT INTO Sessions (SessionId, CampaignId, BannerId, UserAgent, UserIpAddress, UserCountry) VALUES ('%s', '%s', '%s', '%s', '%s', '%s');",
        mysqli_real_escape_string($con, $session->getSessionId()),
        mysqli_real_escape_string($con, $campaignId),
        mysqli_real_escape_string($con, $bannerId),
        mysqli_real_escape_string($con, $userAgent),
        mysqli_real_escape_string($con, $userIpAddress),
        mysqli_real_escape_string($con, $country)
    );
    $result = sendQuery($query);

    // Handle errors
    if (!handleMySqlError($result, $app, 'Could not create the help session.')) {
        return;
    }

    $app->response->headers->set('Content-Type', 'application/json');
    $app->response->setBody(json_encode($responseData));
});

// B) Enqueue in service queue
//    Request: (URL encoded)
//    *  `session_id`: The session which is ready to be enqueued
$app->post('/help/queue', function () use ($app, $con) {

    $sessionId = $app->request->params('session_id');

    // Validation
    // Check to see that the help session exists
    // Save the help session details
    $query = sprintf("SELECT SessionId FROM Sessions WHERE SessionId = '%s';",
        mysqli_real_escape_string($con, $sessionId)
    );
    $result = sendQuery($query);
    $sessionInfo = mysqli_fetch_assoc($result);

    if (!$sessionInfo['SessionId']) {
        $app->response->setStatus(400);
        $app->response->setBody('An invalid session_id was given.');
        return;
    }

    // QueueEntryTime is updated and the user entries the queue
    $query = sprintf("UPDATE Sessions SET QueueEntryTime = NOW() WHERE SessionId = '%s';",
        mysqli_real_escape_string($con, $sessionId)
    );
    $result = sendQuery($query);

    // Handle errors
    if (!handleMySqlError($result, $app, 'Could not enqueue the user.')) {
        return;
    }

    $app->response->setStatus(204);
});

// Representative delivers service
//
// Dequeue from service queue and assign to representative (FIFO). If there is a customer on the
// queue, respond with the help session data and additional data needed to connect. If there isn't
// a customer available on the queue, respond with status code 204 NO CONTENT.
//
// Response: (JSON encoded)
// *  `apiKey`: The OpenTok API Key used to connect to the customer
// *  `sessionId`: The OpenTok Session ID used to the connect to the customer
// *  `token`: The OpenTok Token used to connect to the customer
//
// NOTE: This request allows anonymous access, but if user authentication is required then the 
// identity of the request should be verified (often times with session cookies) before a valid 
// response is given.
$app->delete('/help/queue', function () use ($app, $con, $opentok, $config) {

    // Dequeue the next help session
    $query = "SELECT SessionId FROM Sessions WHERE QueueEntryTime IS NOT NULL AND SessionEndTime IS NULL ORDER BY QueueEntryTime LIMIT 1;";
    $result = sendQuery($query);

    // Handle errors
    if (!handleMySqlError($result, $app, 'Could not dequeue a user.')) {
        return;
    }

    $sessionInfo = mysqli_fetch_assoc($result);

    if (!$sessionInfo['SessionId']) {

        // The queue was empty
        $app->response->setStatus(204);

    } else {

        $representativeName = $app->request->params('representativeName');

        $responseData = array(
            'apiKey' => $config->opentok('key'),
            'sessionId' => $sessionInfo['SessionId'],
            'token' => $opentok->generateToken($sessionInfo['SessionId'])
        );

        // Update the entry on database to set Conversation Start Time and Representative Name
        $query = sprintf("UPDATE Sessions SET ConversationStartTime = NOW(), RepresentativeName = '%s' WHERE SessionId = '%s'",
            mysqli_real_escape_string($con, $representativeName),
            mysqli_real_escape_string($con, $sessionInfo['SessionId'])
        );
        $result = sendQuery($query);

        $app->response->headers->set('Content-Type', 'application/json');
        $app->response->setBody(json_encode($responseData));
    }
});

// Updates the Sessions Table when the session is ended either by the customer or by the representative.
$app->delete('/help/queue/:sessionId', function ($sessionId) use ($app, $con) {
    
    // Dequeue the next help session
    $query = sprintf("UPDATE Sessions SET SessionEndTime = NOW() WHERE SessionId = '%s';",
        mysqli_real_escape_string($con, $sessionId)
    );
    $result = sendQuery($query);

    // Handle errors
    if (!handleMySqlError($result, $app, 'Could not end the session.')) {
        return;
    }

    $app->response->setStatus(204);
});

// Updates the Sessions Table when the representative connects but the customer left the page
$app->post('/help/setToNotConnected/:sessionId', function ($sessionId) use ($app, $con) {
    
    // Dequeue the next help session
    $query = sprintf("UPDATE Sessions SET SessionEndTime = NOW(), ConversationStartTime = NULL, RepresentativeName = NULL WHERE SessionId = '%s';",
        mysqli_real_escape_string($con, $sessionId)
    );
    $result = sendQuery($query);

    // Handle errors
    if (!handleMySqlError($result, $app, 'Could not update the session.')) {
        return;
    }

    $app->response->setStatus(204);
});


// Get Average Metrics of a banner
$app->get('/getAverageMetrics/:campaignId(/:bannerId)', function ($campaignId, $bannerId = '') use ($app, $con) {
    $query = sprintf("
        SELECT
            COUNT(*) 'TotalOfCalls',
            COUNT(ConversationStartTime) 'AnsweredCalls',
            COUNT(*) - COUNT(ConversationStartTime) 'NotAnsweredCalls',
            COUNT(ConversationStartTime) / COUNT(*) 'AnsweringRate',
            AVG(ConversationStartTime - QueueEntryTime) 'AvgQueueTime',
            AVG(SessionEndTime - ConversationStartTime) 'AvgCallDuration'
        FROM
            Sessions WHERE CampaignId = '%s' AND (BannerId = '%s' OR '%s' = '');",
        mysqli_real_escape_string($con, $campaignId),
        mysqli_real_escape_string($con, $bannerId),
        mysqli_real_escape_string($con, $bannerId)
    );
    $result = sendQuery($query);
    $sessionInfo = mysqli_fetch_assoc($result);
    header("Content-Type: application/json");
    echo json_encode($sessionInfo);
});

// Get Full Metrics of a banner
$app->get('/getFullMetrics/:campaignId(/:bannerId)', function ($campaignId, $bannerId = '') use ($app, $con) {
    $query = sprintf("SELECT * FROM Sessions WHERE CampaignId = '%s' AND (BannerId = '%s' OR '%s' = '');",
        mysqli_real_escape_string($con, $campaignId),
        mysqli_real_escape_string($con, $bannerId),
        mysqli_real_escape_string($con, $bannerId)
    );
    $result = sendQuery($query);
    $data = [];
    while($row = mysqli_fetch_assoc($result)){
        array_push($data, $row);
    }
    header("Content-Type: application/json");
    echo json_encode($data);
});


/* ------------------------------------------------------------------------------------------------
 * Application Start
 * -----------------------------------------------------------------------------------------------*/
$app->run();


/* ------------------------------------------------------------------------------------------------
 * Helper functions
 * -----------------------------------------------------------------------------------------------*/

function handleMySqlError($result, $app, $message = '') {
    global $con;
    $success = true;
    if (!$result) {
        $app->response->setStatus(500);
        $app->response->setBody($message . '\n' . mysqli_error($con));
        $success = false;
    }
    return $success;
}

// Function to get the client IP address
function get_user_ip_address() {
    $ipaddress = '';
    if (getenv('HTTP_CLIENT_IP'))
        $ipaddress = getenv('HTTP_CLIENT_IP');
    else if(getenv('HTTP_X_FORWARDED_FOR'))
        $ipaddress = getenv('HTTP_X_FORWARDED_FOR');
    else if(getenv('HTTP_X_FORWARDED'))
        $ipaddress = getenv('HTTP_X_FORWARDED');
    else if(getenv('HTTP_FORWARDED_FOR'))
        $ipaddress = getenv('HTTP_FORWARDED_FOR');
    else if(getenv('HTTP_FORWARDED'))
       $ipaddress = getenv('HTTP_FORWARDED');
    else if(getenv('REMOTE_ADDR'))
        $ipaddress = getenv('REMOTE_ADDR');
    else
        $ipaddress = 'UNKNOWN';
    return $ipaddress;
}

// Function to get the country of the user based on IP address
function get_user_country($userIpAddress) {
    if ($userIpAddress != 'UNKNOWN') {
        $details = json_decode(file_get_contents("http://ipinfo.io/{$userIpAddress}/json"));
        return property_exists($details, 'country') ? $details->country : 'UNKNOWN';
    } else {
        return 'UNKNOWN';
    }
}
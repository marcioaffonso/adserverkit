# OpenTok AdServer Kit 

An OpenTok Kit for integration with an adserver and creating a customer service application.

[![Deploy](https://www.herokucdn.com/deploy/button.png)](https://heroku.com/deploy)

## Installation

1. Clone the repository.

2. Copy the `config/development/opentok.php.sample` file to `config/development/opentok.php` and
   replace the `key` and `secret` settings with your OpenTok API key and secret, from the [TokBox
   Dashboard](https://dashboard.tokbox.com).

3. Edit the `config/development/mysql.php` file to configure a MySQL connection.
   The format is mysql://username:password@mysqlurl:port/database_name

4. Use [Composer](https://getcomposer.org/)(included) to install dependencies:
   `./composer.phar install`

5. Set the document root for your web server (such as Apache, nginx, etc.) to the `web` directory
   of this project. In the case of Apache, the provided `web\.htaccess` file handles URL rewriting.
   See the [Slim Route URL Rewriting Guide](http://docs.slimframework.com/#Route-URL-Rewriting)
   for more details.

## Usage

1. You have to set your banner link url to the index page of the adserver kit, which will be the
   customer's page. The page requires two parameters in query string:
   -  `campaignId` -- Id of a campaign previously created on the adserver
   -  `bannerId` -- Id of the specific banner which was clicked by the user
   
   Example of how the page has to be called:
   - http://localhost/index.php?campaignId=5&bannerId=3

2. You will be asked to allow access to your camera and microphone; allow them.

3. Have another user (possibly in another window or tab) visit the `/rep` page on the same server.
   This is the representative's page.

4. The representative will immediately be prompted for a name. Submitting the form also requires
   allowing access to the camera and microphone.

5. In order to start a chat with the customer, the representative will need to click on the "Next
   Customer" button. The first customer who successfully requested the representative will be
   matched first.

   If there was more than one customer who had successfully requested a representative, that
   customer would be next after the call ends. If there was more than one representative online,
   then more than one customer can also be serviced at a single time.

6. Once either of the users chooses to "End" or if the customer leaves the page, the call will
   complete. The representative needs to once again click on the "Get Customer" button to indicate
   that he or she is ready to speak to another customer.


## View Metrics

1. You can then see metrics about the calls for a specific campaign and banner.
There are two methods available for metrics:

   -  /getAverageMetrics/[campaignId]/[bannerId]
      - This method returns a JSON with general information about sessions for the specified campaign
      and banner (bannerId parameter is optional). The object returned has the following properties:
         - TotalOfCalls: Number of calls
         - AnsweredCalls: Number of answered calls
         - NotAnsweredCalls: Number of not answered calls
         - AnsweringRate: Answering rate (%)
         - AvgQueueTime: Average time that customers have been in the queue (seconds)
         - AvgCallDuration: Average time of duration of calls (seconds)

   -  /getFullMetrics/[campaignId]/[bannerId]
      - This method returns a JSON with full information about all sessions for the specified campaign
      and banner (bannerId parameter is optional). The object returns an array of objects, on which each
      of them has the following properties:
         - CampaignId: Id of the campaign
         - BannerId: Id of the banner
         - QueueEntryTime: Datetime when the customer joined the queue
         - ConversationStartTime: Datetime when the conversation between user and agent started
         - SessionEndTime: Datetime when the conversation ended or when the user left the page
         - UserIpAddress: Customer IP Address
         - UserAgent: Customer Agent
         - UserCountry: Customer Country
         - RepresentativeName: Name of the representative who talked to the customer
         - Sessionid: Id of the Session


## Code and Conceptual Walkthrough

### Technologies

This application uses some of the same frameworks and libraries as the
[HelloWorld](https://github.com/opentok/OpenTok-PHP-SDK/tree/master/sample/HelloWorld) sample. If
you have not already gotten familiar with the code in that project, consider doing so before
continuing. Slim is a minimalistic PHP framework, so its patterns can be applied to other
frameworks and even other languages.

The client side of the application makes use of a few third party libraries.
[Bootstrap](http://getbootstrap.com/) is used to help style the UI and for reusable components such
as buttons and modals. [jQuery](http://jquery.com/) is used for common AJAX and DOM manipulation
utilities. The [EventEmitter2](https://github.com/asyncly/EventEmitter2) library is used to add an
evented API to objects. The [setImmediate](https://github.com/YuzuJS/setImmediate) library is used
to provide a cross-browser implementation to schedule tasks.

The server side also uses [MySQL] for data storage.

## Requirements

*  PHP
*  MySQL

## Appendix -- Deploying to Heroku

Heroku is a PaaS (Platform as a Service) that can be used to deploy simple and small applications
for free. For that reason, you may choose to experiment with this code and deploy it using Heroku.

Use the button at the top of the README to deploy to Heroku in one click!

If you'd like to deploy manually, here is some additional information:

*  The provided `Procfile` describes a web process which can launch this application.

*  This application uses MYSQL. Use heroku addons:create cleardb:ignite to install the ClearDB addon
   for Heroku to use MYSQL.

*  Use Heroku config to set the following keys:

   -  `OPENTOK_KEY` -- Your OpenTok API Key
   -  `OPENTOK_SECRET` -- Your OpenTok API Secret
   -  `SLIM_MODE` -- Set this to `production` when the environment variables should be used to
      configure the application. The Slim application will only start reading its Heroku config when
      its mode is set to `'production'`

   You should avoid committing configuration and secrets to your code, and instead use Heroku's
   config functionality.


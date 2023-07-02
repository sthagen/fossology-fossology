<?php
/*
 SPDX-FileCopyrightText: © 2023 Samuel Dushimimana <dushsam100@gmail.com>

 SPDX-License-Identifier: GPL-2.0-only
*/

/**
 * @file
 * @brief Tests for UploadTreeController
 */

namespace {
  if (!function_exists('RepPathItem')) {
    function RepPathItem($Item, $Repo = "files")
    {
      return dirname(__DIR__) . "/tests.xml";
    }
  }
}

namespace Fossology\UI\Api\Test\Controllers {

  use ClearingView;
  use Fossology\Lib\Auth\Auth;
  use Fossology\Lib\Dao\UploadDao;
  use Fossology\Lib\Data\DecisionTypes;
  use Fossology\Lib\Data\UploadStatus;
  use Fossology\Lib\Db\DbManager;
  use Fossology\UI\Api\Controllers\UploadTreeController;
  use Fossology\UI\Api\Helper\DbHelper;
  use Fossology\UI\Api\Helper\ResponseHelper;
  use Fossology\UI\Api\Helper\RestHelper;
  use Fossology\UI\Api\Models\Info;
  use Fossology\UI\Api\Models\InfoType;
  use Mockery as M;
  use Slim\Psr7\Factory\StreamFactory;
  use Slim\Psr7\Headers;
  use Slim\Psr7\Request;
  use Slim\Psr7\Response;
  use Slim\Psr7\Uri;

  /**
   * @class UploadControllerTest
   * @brief Unit tests for UploadController
   */
  class UploadTreeControllerTest extends \PHPUnit\Framework\TestCase
  {
    /**
     * @var DbHelper $dbHelper
     * DbHelper mock
     */
    private $dbHelper;

    /**
     * @var DbManager $dbManager
     * Dbmanager mock
     */
    private $dbManager;

    /**
     * @var RestHelper $restHelper
     * RestHelper mock
     */
    private $restHelper;

    /**
     * @var UploadTreeController $uploadTreeController
     * UploadTreeController mock
     */
    private $uploadTreeController;

    /**
     * @var UploadDao $uploadDao
     * UploadDao mock
     */
    private $uploadDao;

    /**
     * @var StreamFactory $streamFactory
     * Stream factory to create body streams.
     */
    private $streamFactory;

    /**
     * @var M\MockInterface $viewFilePlugin
     * ViewFilePlugin mock
     */
    private $viewFilePlugin;

    /**
     * @var M\MockInterface $viewLicensePlugin
     * ViewFilePlugin mock
     */
    private $viewLicensePlugin;

    /**
     * @var DecisionTypes $decisionTypes
     * Decision types object
     */
    private $decisionTypes;

    /**
     * @brief Setup test objects
     * @see PHPUnit_Framework_TestCase::setUp()
     */
    protected function setUp(): void
    {
      global $container;
      $this->userId = 2;
      $this->groupId = 2;
      $container = M::mock('ContainerBuilder');
      $this->dbHelper = M::mock(DbHelper::class);
      $this->dbManager = M::mock(DbManager::class);
      $this->restHelper = M::mock(RestHelper::class);
      $this->uploadDao = M::mock(UploadDao::class);
      $this->decisionTypes = M::mock(DecisionTypes::class);
      $this->viewFilePlugin = M::mock('ui_view');
      $this->viewLicensePlugin = M::mock(ClearingView::class);

      $this->restHelper->shouldReceive('getPlugin')
        ->withArgs(array('view'))->andReturn($this->viewFilePlugin);
      $this->restHelper->shouldReceive('getPlugin')
        ->withArgs(array('view-license'))->andReturn($this->viewLicensePlugin);

      $this->dbManager->shouldReceive('getSingleRow')
        ->withArgs([M::any(), [$this->groupId, UploadStatus::OPEN,
          Auth::PERM_READ]]);
      $this->dbHelper->shouldReceive('getDbManager')->andReturn($this->dbManager);

      $this->restHelper->shouldReceive('getDbHelper')->andReturn($this->dbHelper);
      $this->restHelper->shouldReceive('getGroupId')->andReturn($this->groupId);
      $this->restHelper->shouldReceive('getUserId')->andReturn($this->userId);
      $this->restHelper->shouldReceive('getUploadDao')
        ->andReturn($this->uploadDao);
      $container->shouldReceive('get')->withArgs(array(
        'helper.restHelper'))->andReturn($this->restHelper);
      $container->shouldReceive('get')->withArgs(['decision.types'])->andReturn($this->decisionTypes);
      $this->uploadTreeController = new UploadTreeController($container);
      $this->assertCountBefore = \Hamcrest\MatcherAssert::getCount();
      $this->streamFactory = new StreamFactory();
    }

    /**
     * Helper function to get JSON array from response
     *
     * @param Response $response
     * @return array Decoded response
     */
    private function getResponseJson($response)
    {
      $response->getBody()->seek(0);
      return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @test
     * -# Test for UploadController::viewLicenseFile() with valid status
     * -# Check if response status is 200 and the body has the expected contents
     */
    public function testViewLicenseFile()
    {
      $upload_pk = 1;
      $item_pk = 200;
      $expectedContent = file_get_contents(dirname(__DIR__) . "/tests.xml");

      $this->dbHelper->shouldReceive('doesIdExist')
        ->withArgs(["upload", "upload_pk", $upload_pk])->andReturn(true);
      $this->uploadDao->shouldReceive("getUploadtreeTableName")->withArgs([$upload_pk])->andReturn("uploadtree");
      $this->dbHelper->shouldReceive('doesIdExist')
        ->withArgs(["uploadtree", "uploadtree_pk", $item_pk])->andReturn(true);

      $this->viewFilePlugin->shouldReceive('getText')->withArgs([M::any(), 0, 0, -1, null, false, true])->andReturn($expectedContent);

      $expectedResponse = new ResponseHelper();
      $expectedResponse->getBody()->write($expectedContent);
      $actualResponse = $this->uploadTreeController->viewLicenseFile(null, new ResponseHelper(), ['id' => $upload_pk, 'itemId' => $item_pk]);
      $this->assertEquals($expectedResponse->getStatusCode(), $actualResponse->getStatusCode());
      $this->assertEquals($expectedResponse->getBody()->getContents(), $actualResponse->getBody()->getContents());
    }


    /**
     * @test
     * -# Test for UploadTreeController::setClearingDecision() for setting a clearing decision
     * -# Check if response status is 200 and response body matches
     */
    public function testSetClearingDecisionReturnsOk()
    {
      $upload_pk = 1;
      $item_pk = 200;
      $rq = [
        "decisionType" => 3,
        "globalDecision" => false,
      ];
      $dummyDecisionTypes = array_map(function ($i) {
        return $i;
      }, range(1, 7));

      $this->decisionTypes->shouldReceive('getMap')
        ->andReturn($dummyDecisionTypes);
      $this->uploadDao->shouldReceive("getUploadtreeTableName")->withArgs([$item_pk])->andReturn("uploadtree");
      $this->dbHelper->shouldReceive('doesIdExist')
        ->withArgs(["uploadtree", "uploadtree_pk", $item_pk])->andReturn(true);


      $this->viewLicensePlugin->shouldReceive('updateLastItem')->withArgs([2, 2, $item_pk, $item_pk]);

      $info = new Info(200, "Successfully set decision", InfoType::INFO);

      $expectedResponse = (new ResponseHelper())->withJson($info->getArray(), $info->getCode());
      $reqBody = $this->streamFactory->createStream(json_encode(
        $rq
      ));
      $requestHeaders = new Headers();
      $requestHeaders->setHeader('Content-Type', 'application/json');
      $request = new Request("PUT", new Uri("HTTP", "localhost"),
        $requestHeaders, [], [], $reqBody);
      $actualResponse = $this->uploadTreeController->setClearingDecision($request, new ResponseHelper(), ['id' => $upload_pk, 'itemId' => $item_pk]);

      $this->assertEquals($expectedResponse->getStatusCode(),
        $actualResponse->getStatusCode());
      $this->assertEquals($this->getResponseJson($expectedResponse),
        $this->getResponseJson($actualResponse));
    }

    /**
     * @test
     * -# Test for UploadTreeController::setClearingDecision() for setting a clearing decision
     * -# Check if response status is 400, if the given decisionType is invalid
     */
    public function testSetClearingDecisionReturnsError()
    {
      $upload_pk = 1;
      $item_pk = 200;
      $rq = [
        "decisionType" => 40,
        "globalDecision" => false,
      ];
      $dummyDecisionTypes = array_map(function ($i) {
        return $i;
      }, range(1, 7));

      $this->decisionTypes->shouldReceive('getMap')
        ->andReturn($dummyDecisionTypes);
      $this->uploadDao->shouldReceive("getUploadtreeTableName")->withArgs([$item_pk])->andReturn("uploadtree");
      $this->dbHelper->shouldReceive('doesIdExist')
        ->withArgs(["uploadtree", "uploadtree_pk", $item_pk])->andReturn(true);

      $this->viewLicensePlugin->shouldReceive('updateLastItem')->withArgs([2, 2, $item_pk, $item_pk]);

      $info = new Info(400, "Decision Type should be one of the following keys: " . implode(", ", $dummyDecisionTypes), InfoType::ERROR);

      $expectedResponse = (new ResponseHelper())->withJson($info->getArray(), $info->getCode());
      $reqBody = $this->streamFactory->createStream(json_encode(
        $rq
      ));
      $requestHeaders = new Headers();
      $requestHeaders->setHeader('Content-Type', 'application/json');
      $request = new Request("PUT", new Uri("HTTP", "localhost"),
        $requestHeaders, [], [], $reqBody);

      $actualResponse = $this->uploadTreeController->setClearingDecision($request, new ResponseHelper(), ['id' => $upload_pk, 'itemId' => $item_pk]);
      $this->assertEquals($expectedResponse->getStatusCode(), $actualResponse->getStatusCode());
    }
  }
}

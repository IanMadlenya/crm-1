<?php

namespace Oro\Bundle\WindowsBundle\Tests\Functional\API;

use Acme\Bundle\TestsBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Acme\Bundle\TestsBundle\Test\ToolsAPI;

class RestApiTest extends WebTestCase
{
    /**
     * @var \Symfony\Bundle\FrameworkBundle\Client
     */
    protected $client;

    /**
     * @var \Symfony\Component\Routing\RouterInterface
     */
    protected $router;

    protected static $entity;

    public function setUp()
    {
        $this->client = static::createClient(array(), ToolsAPI::generateBasicHeader());
        $this->router = $this->client->getContainer()->get('router');
    }

    protected function tearDown()
    {
        unset($this->client);
    }

    /**
     * Test POST
     */
    public function testPost()
    {
        self::$entity = array(
            'data' => array(
                'position' => '0',
                'title' => 'Some title'
            )
        );

        $this->client->request(
            'POST',
            $this->router->generate('oro_api_post_windows'),
            self::$entity,
            array(),
            ToolsAPI::generateWsseHeader()
        );

        /** @var $result Response */
        $result = $this->client->getResponse();

        $this->assertJsonResponse($result, 201);

        $resultJson = json_decode($result->getContent(), true);

        $this->assertArrayHasKey("id", $resultJson);
        $this->assertGreaterThan(0, $resultJson["id"]);

        self::$entity['id'] = $resultJson["id"];
    }

    /**
     * Test PUT
     *
     * @depends testPost
     */
    public function testPut()
    {
        $this->assertNotEmpty(self::$entity);

        self::$entity['data']['position'] = 100;

        $this->client->request(
            'PUT',
            $this->router->generate('oro_api_put_windows', array('windowId' => self::$entity['id'])),
            self::$entity,
            array(),
            ToolsAPI::generateWsseHeader()
        );

        /** @var $result Response */
        $result = $this->client->getResponse();

        $this->assertJsonResponse($result, 200);

        $resultJson = json_decode($result->getContent(), true);

        $this->assertCount(0, $resultJson);
    }

    /**
     * Test GET
     *
     * @depends testPut
     */
    public function testGet()
    {
        $this->assertNotEmpty(self::$entity);

        $this->client->request(
            'GET',
            $this->router->generate('oro_api_get_windows'),
            array(),
            array(),
            ToolsAPI::generateWsseHeader()
        );

        /** @var $result Response */
        $result = $this->client->getResponse();

        $this->assertJsonResponse($result, 200);

        $resultJson = json_decode($result->getContent(), true);

        $this->assertNotEmpty($resultJson);
        $this->assertArrayHasKey('id', $resultJson[0]);
    }

    /**
     * Test DELETE
     *
     * @depends testPut
     */
    public function testDelete($itemType)
    {
        $this->assertNotEmpty(self::$entity);

        $this->client->request(
            'DELETE',
            $this->router->generate('oro_api_delete_windows', array('windowId' => self::$entity['id'])),
            array(),
            array(),
            ToolsAPI::generateWsseHeader()
        );

        /** @var $result Response */
        $result = $this->client->getResponse();

        $this->assertJsonResponse($result, 204);
        $this->assertEmpty($result->getContent());
    }

    /**
     * Test 404
     *
     * @depends testDelete
     */
    public function testNotFound()
    {
        $this->assertNotEmpty(self::$entity);

        $this->client->request(
            'PUT',
            $this->router->generate('oro_api_put_windows', array('windowId' => self::$entity['id'])),
            self::$entity,
            array(),
            ToolsAPI::generateWsseHeader()
        );

        /** @var $result Response */
        $result = $this->client->getResponse();
        $this->assertJsonResponse($result, 404);

        $this->client->restart();

        $this->client->request(
            'DELETE',
            $this->router->generate('oro_api_delete_windows', array('windowId' => self::$entity['id'])),
            array(),
            array(),
            ToolsAPI::generateWsseHeader()
        );

        /** @var $result Response */
        $result = $this->client->getResponse();

        $this->assertJsonResponse($result, 404);
    }

    /**
     * Test Unauthorized
     *
     * @depends testNotFound
     */
    public function testUnauthorized()
    {
        $this->assertNotEmpty(self::$entity);

        $requests = array(
            'GET'    => $this->router->generate('oro_api_get_windows'),
            'POST'   => $this->router->generate('oro_api_post_windows'),
            'PUT'    => $this->router->generate('oro_api_put_windows', array('windowId' => self::$entity['id'])),
            'DELETE' => $this->router->generate('oro_api_delete_windows', array('windowId' => self::$entity['id'])),
        );

        foreach ($requests as $requestType => $url) {
            $this->client->request($requestType, $url);

            /** @var $result Response */
            $response = $this->client->getResponse();

            $this->assertEquals(401, $response->getStatusCode());

            $this->client->restart();
        }
    }

    /**
     * Test Empty Body error
     *
     * @depends testNotFound
     */
    public function testEmptyBody()
    {
        $this->assertNotEmpty(self::$entity);

        $requests = array(
            'POST' => $this->router->generate('oro_api_post_windows'),
            'PUT'  => $this->router->generate('oro_api_put_windows', array('windowId' => self::$entity['id'])),
        );

        foreach ($requests as $requestType => $url) {
            $this->client->request(
                $requestType,
                $url,
                array(),
                array(),
                ToolsAPI::generateWsseHeader()
            );

            /** @var $response Response */
            $response = $this->client->getResponse();

            $this->assertJsonResponse($response, 400);

            $responseJson = json_decode($response->getContent(), true);

            $this->assertArrayHasKey('message', $responseJson);
            $this->assertEquals('Wrong JSON inside POST body', $responseJson['message']);

            $this->client->restart();
        }
    }

    /**
     * Test API response status
     *
     * @param Response $response
     * @param int      $statusCode
     */
    protected function assertJsonResponse($response, $statusCode = 200)
    {
        $this->assertEquals(
            $statusCode,
            $response->getStatusCode()
        );

        $this->assertTrue(
            $response->headers->contains('Content-Type', 'application/json')
        );
    }
}

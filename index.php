<?php

require_once __DIR__ . '/vendor/autoload.php';

use Google\CloudFunctions\FunctionsFramework;
use Psr\Http\Message\ServerRequestInterface;

FunctionsFramework::http('run', 'run');

function run(ServerRequestInterface $request): string
{
	$context = [];
	Logger::info('starting', $context);

	$config = Config::load(__DIR__ . "/config.ini");

	$body = json_decode($request->getBody()->getContents(), true);
	$resource_url = $body["resource_url"] ?? null;

	$context['body'] = $body;
	Logger::info('parsing request', $context);

	if (!$resource_url) {
		Logger::error("no resource_url");
		return '';
	}

	$context['resource_url'] = $resource_url;
	Logger::info('checking resource', $context);

	$secret = new Secret(
		$config['project_id'],
		$config['shipstation_secret_id'],
		$config['shipstation_secret_version']
	);

	$shipstation_client = new ShipStation($secret);
	$resource = $shipstation_client->getResource($resource_url);
	$orders = $resource['orders'] ?? [];
	if (!$orders) {
		Logger::error("no orders");
		return '';
	}

	$context['order_count'] = count($orders);
	Logger::info('processing orders', $context);

	foreach ($orders as $order) {
		$order_id = $order['orderId'];
		$original_note = $order['customerNotes'] ?? "";
		$parser = new NoteParser();
		$parser->parse($original_note);
		$order['customerNotes'] = $parser->getNote();
		$order['advancedOptions']['customField1'] = $parser->getExtra();
		$shipstation_client->createOrUpdateOrder($order);

		Logger::info('updated order ' . $order_id , $context);
	}

	Logger::info('complete', $context);

	return "ok";
}


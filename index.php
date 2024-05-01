<?php

require_once __DIR__ . '/vendor/autoload.php';

use Google\CloudFunctions\FunctionsFramework;
use Psr\Http\Message\ServerRequestInterface;

FunctionsFramework::http('run', 'run');

function run(ServerRequestInterface $request): string
{
	$config = Config::load(__DIR__ . "/config.ini");

	$context['cloud_function_name'] = $config['cloud_function_name'];
	Logger::info('starting', $context);

	$body = json_decode($request->getBody()->getContents(), true);
	$resource_url = $body["resource_url"] ?? null;

	$context['request_body'] = $body;
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

	$order_count = count($orders);
	$context['order_count'] = $order_count;
	Logger::info("processing $order_count order(s)", $context);

	foreach ($orders as $order) {
		$order_id = $order['orderId'];
		$original_note = $order['customerNotes'] ?? "";
		$parser = new NoteParser();
		$parser->parse($original_note);
		$order['customerNotes'] = $parser->getNote();
		$order['advancedOptions']['customField1'] = $original_note;
		try {
			$response = $shipstation_client->createOrUpdateOrder($order);
			$order_context = array_merge($context, [
				"note_original" => $original_note,
				"note_parsed" => $parser->getNote(),
				"note_response" => $response['customerNotes'] ?? "undefined",
			]);
			Logger::info("updated order $order_id" , $order_context);
		} catch (Exception $e) {
			$error_context = array_merge($context, [
				"error_message" => $e->getMessage()
			]);
			Logger::error("error updating order $order_id", $error_context);
		}
	}

	Logger::info('complete', $context);

	return "ok";
}


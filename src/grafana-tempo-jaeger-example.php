<?php


/**
 * Example of PHP tracing implementation for Grafana Tempo using OpenTelemetry
 * package ang Jaeger Exporter.
 */


declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Jaeger\Exporter as JaegerExporter;
use OpenTelemetry\Sdk\Trace\Attributes;
use OpenTelemetry\Sdk\Trace\Clock;
use OpenTelemetry\Sdk\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\Sdk\Trace\SamplingResult;
use OpenTelemetry\Sdk\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\Sdk\Trace\TracerProvider;
use OpenTelemetry\Trace as API;

/*
Fill here right host and port of local Tempo or Grafana Agent collector:
Default ports in Grafana Agent:
```
tempo:
  configs:
  - name: default
    receivers:
      jaeger:
        protocols:
          grpc: # 14250/tcp
          thrift_binary: # 6832/udp
          thrift_compact: # 6831/udp
          thrift_http: # 14268/tcp
      opencensus: # 55678/tcp
      otlp:
        protocols:
          grpc: # 55680/tcp
          http: # 55680/tcp
      zipkin: # 9411/tcp
```
In this example we will use  Zipkin 9411/tcp port as most common variant.
*/
$tempoEndpoint = 'http://localhost:9411/api/v2/spans';


if(strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') !== FALSE) {
    echo '<pre>';
}

$sampler = new AlwaysOnSampler();
$samplerUniqueId = md5((string) microtime(true));

$samplingResult = $sampler->shouldSample(
    Context::getCurrent(),
    $samplerUniqueId,
    'io.opentelemetry.example',
    API\SpanKind::KIND_INTERNAL
);
echo PHP_EOL . 'Created AlwaysOnSampler with id ' . $samplerUniqueId;

$operationName = 'My test operation one';
$exporter = new JaegerExporter(
    $operationName,
    $tempoEndpoint,
    new Client(),
    new HttpFactory(),
    new HttpFactory()
);

if (SamplingResult::RECORD_AND_SAMPLE === $samplingResult->getDecision()) {
    echo PHP_EOL . 'Starting ' . $operationName;
    $tracer = (new TracerProvider())
        ->addSpanProcessor(new BatchSpanProcessor($exporter, Clock::get()))
        ->getTracer('io.opentelemetry.contrib.php');
    $spanMain = $tracer->startAndActivateSpan('span_main');
    echo sprintf(
        PHP_EOL . 'Exporting root trace: %s, Span: %s',
        $spanMain->getContext()->getTraceId(),
        $spanMain->getContext()->getSpanId()
    );

    for ($i = 0; $i < 5; $i++) {
        // start a span, register some events
        $timestamp = Clock::get()->timestamp();
        $span = $tracer->startAndActivateSpan('subspan for step ' . $i);

        $spanParent = $span->getParent();
        echo sprintf(
            PHP_EOL . 'Exporting subtrace step %d: Trace id: %s, Span: %s, Parent: %s',
            $i,
            $span->getContext()->getTraceId(),
            $span->getContext()->getSpanId(),
            $spanParent ? $spanParent->getSpanId() : 'None'
        );

        $span->setAttribute('remote_ip', '1.2.3.4')
            ->setAttribute('country', 'RU');

        $span->addEvent('processing step ' . $i, $timestamp, new Attributes([
            'user_id' => $i,
            'age' => rand(1,100),
        ]));
        $span->addEvent('generated_session', $timestamp, new Attributes([
            'id' => md5((string) microtime(true)),
        ]));
        usleep(rand(1111,7777));

        if($i == 3) {
            try {
                throw new Exception('Record exception test event on step ' . $i);
            } catch (Exception $exception) {
                $span->recordException($exception);
            }
        }

        $span->end();
    }
    $spanMain->end();

    echo PHP_EOL . $operationName . ' is completed!';
} else {
    echo PHP_EOL . $operationName . ' tracing is not enabled';
}

echo PHP_EOL;
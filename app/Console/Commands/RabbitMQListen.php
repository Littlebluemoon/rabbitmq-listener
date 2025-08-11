<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Illuminate\Support\Facades\Log;
use Elastic\Elasticsearch\ClientBuilder;

class RabbitMQListen extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "rabbitmq:listen";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Listen to RabbitMQ messages";

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Log::info("Job has started");
        Log::info("Creds : " . config("queue.connections.rabbitmq.host") . ":" . config("queue.connections.rabbitmq.port"));
        set_time_limit(0);
        // dd("creating connection username : " . env('RABBITMQ_LOGIN') . " password : " . env('RABBITMQ_PASSWORD'));
        $connection = new AMQPStreamConnection(
            config("queue.connections.rabbitmq.host"),
            config("queue.connections.rabbitmq.port"),
            config("queue.connections.rabbitmq.login"),
            config("queue.connections.rabbitmq.password"),
            config("queue.connections.rabbitmq.vhost"),
            null, // timeout
            "AMQPLAIN", // auth method
            null,
            "vi_VN",
            null,
            1 // heartbeat
        );

        $channel = $connection->channel();
        $channel->basic_qos(null, 1, null);

        $channel->queue_declare(config("queue.connections.rabbitmq.queue"), false, true, false, false);

        Log::info("Instantiating client for the first time");
            error_log("Client is not created, creating one");
            $hosts = [config("queue.connections.elasticsearch.host")];
            Log::info("Host : " . implode($hosts));
            $esClient = ClientBuilder::create()
                ->setHosts($hosts)
                ->setBasicAuthentication(
                    config("queue.connections.elasticsearch.username"),
                    config("queue.connections.elasticsearch.password")
                )
                ->setCABundle(config("queue.connections.elasticsearch.certificate"))
                ->build();

        $callback = function (AMQPMessage $msg) use ($esClient){
            try {
                $msgAsJSON = json_decode($msg->getBody(), true);

                $requestLog = [
                    "index" => config("queue.connections.elasticsearch.index"),
                    "type" => "_doc",
                    "body" => [
                        "requestId" => $msgAsJSON["requestId"],
                        "referer" => $msgAsJSON["referer"],
                        "method" => $msgAsJSON["method"],
                        "startDateTime" => $msgAsJSON["startDateTime"],
                        "ip" => $msgAsJSON["ip"],
                        "url" => $msgAsJSON["url"],
                        "statusCode" => $msgAsJSON["statusCode"],
                        // 'response' => $msgAsJSON['response'],
                        "executionTime" => $msgAsJSON["executionTime"],
                        "interface" => $msgAsJSON["interface"],
                    ],
                ];
                $esClient->index($requestLog);

                // $msg->delivery_info['channel']->basic_ack('');
            } catch (\Exception $e) {
                error_log($e->getMessage());
                Log::error("Something really terrible happened when sending message : " . $e->getMessage());
            }
        };

        $channel->basic_consume(config("queue.connections.rabbitmq.queue"), "", false, true, false, false, $callback);

        while ($channel->is_consuming()) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();
    }
}

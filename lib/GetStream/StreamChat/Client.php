<?php

declare(strict_types=0);

namespace GetStream\StreamChat;

use DateTime;
use Exception;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\MultipartStream;

/**
 * A constant class for internal usage
 * @internal
 */
class Constant
{
    const VERSION = '2.9.0';
}

/**
 * A client for the Stream Chat API
 */
class Client
{
    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var string
     */
    private $apiSecret;

    /**
     * @var string
     */
    private $authToken;

    /**
     * @var array
     */
    private $guzzleOptions = [];

    /**
     * @var GuzzleClient
     */
    private $client;

    /**
     * @var JwtHandler
     */
    private $jwtHandler;

    /**
     * @deprecated Both `$apiVersion` and `$location` variables are deprecated and will be removed in a future version.
     */
    public function __construct(string $apiKey, string $apiSecret, string $apiVersion = null, string $location = null, float $timeout = null)
    {
        if ($apiVersion !== null || $location !== null) {
            $warn = "\$apiVersion and \$location parameters are deprecated and will be removed in a future version. ";
            $warn .= "Please provide null to suppress this warning.";
            trigger_error($warn, E_USER_NOTICE);
        }

        $this->apiKey = $apiKey ?? getenv("STREAM_KEY");
        $this->apiSecret = $apiSecret ?? getenv("STREAM_SECRET");

        if (!$this->apiKey || !$this->apiSecret) {
            throw new StreamException('API key and secret are required.');
        }

        if ($timeout !== null) {
            $timeout = $timeout;
        } elseif (getenv("STREAM_CHAT_TIMEOUT")) {
            $timeout = floatval(getenv("STREAM_CHAT_TIMEOUT"));
        } else {
            $timeout = 3.0;
        }

        $this->jwtHandler = new JwtHandler();
        $this->authToken = $this->jwtHandler->createServerSideToken($this->apiSecret);
        $this->client = new GuzzleClient([
            'base_uri' => $this->getBaseUrl(),
            'timeout' => $timeout,
            'handler' => HandlerStack::create(),
            'headers' => ['Accept-Encoding' => 'gzip'],
        ]);
    }

    /** Sets the location for the URL. Deprecated, and will be removed in a future version.
     * Stream's new Edge infrastructure removes the need to specifically set a regional URL.
     * The baseURL is https://chat.stream-io-api.com regardless of region.
     * @deprecated This method will be removed in a future version.
     */
    public function setLocation(string $location): void
    {
    }

    /** Returns the base url of the backend.
     */
    public function getBaseUrl(): string
    {
        $envVarKeys = ["STREAM_CHAT_URL", "STREAM_BASE_CHAT_URL", "STREAM_BASE_URL"];

        foreach ($envVarKeys as $envVarKey) {
            $baseUrl = getenv($envVarKey);

            if ($baseUrl) {
                return $baseUrl;
            }
        }

        $localPort = getenv('STREAM_LOCAL_API_PORT');
        if ($localPort) {
            return "http://localhost:$localPort";
        }

        return "https://chat.stream-io-api.com";
    }

    /**
     * @internal
     */
    public function buildRequestUrl(string $uri): string
    {
        $baseUrl = $this->getBaseUrl();
        return "{$baseUrl}/{$uri}";
    }

    /** Sets the underlying HTTP client. Make sure you set a base_uri.
     */
    public function setHttpClient(\GuzzleHttp\Client $client): void
    {
        $this->client = $client;
    }

    /** Sets a Guzzle HTTP option that add to the request. See `\GuzzleHttp\RequestOptions`.
     * @param mixed $value
     */
    public function setGuzzleDefaultOption(string $option, $value): void
    {
        $this->guzzleOptions[$option] = $value;
    }

    /**
     * @return string[]
     */
    private function getHttpRequestHeaders(): array
    {
        return [
            'Authorization' => $this->authToken,
            'Content-Type' => 'application/json',
            'stream-auth-type' => 'jwt',
            'X-Stream-Client' => 'stream-chat-php-client-' . Constant::VERSION,
        ];
    }

    /**
     * @throws StreamException
     */
    public function makeHttpRequest(string $uri, string $method, $data = [], array $queryParams = [], array $multipart = []): StreamResponse
    {
        $queryParams['api_key'] = $this->apiKey;
        $headers = $this->getHttpRequestHeaders();

        $uri = (new Uri($this->buildRequestUrl($uri)))
            ->withQuery(http_build_query($queryParams));

        $options = $this->guzzleOptions;

        if ($multipart) {
            $boundary = '----44cf242ea3173cfa0b97f80c68608c4c';
            $options['body'] = new MultipartStream($multipart, $boundary);
            $headers['Content-Type'] = "multipart/form-data;boundary=" . $boundary;
        } else {
            if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
                $options['json'] = $data;
            }
        }

        $options['headers'] = $headers;

        try {
            $response = $this->client->request($method, $uri, $options);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $msg = $response->getBody()->getContents();
            $code = $response->getStatusCode();
            $previous = $e;
            throw new StreamException($msg, $code, $previous);
        }

        $body = $response->getBody()->getContents();

        return new StreamResponse(json_decode($body, true), $response);
    }

    /** @link https://getstream.io/chat/docs/php/tokens_and_authentication/?language=php
     * @throws StreamException
     */
    public function createToken(string $userId, int $expiration = null, int $issuedAt = null): string
    {
        $payload = ['user_id' => $userId];

        if ($expiration !== null) {
            if (gettype($expiration) !== 'integer') {
                throw new StreamException("expiration must be a unix timestamp");
            }
            $payload['exp'] = $expiration;
        }

        if ($issuedAt !== null) {
            if (gettype($issuedAt) !== 'integer') {
                throw new StreamException("issuedAt must be a unix timestamp");
            }
            $payload['iat'] = $issuedAt;
        }

        return $this->jwtHandler->encode($this->apiSecret, $payload);
    }

    /**
     * @throws StreamException
     */
    public function get(string $uri, array $queryParams = []): StreamResponse
    {
        return $this->makeHttpRequest($uri, "GET", [], $queryParams);
    }

    /**
     * @throws StreamException
     */
    public function delete(string $uri, array $queryParams = []): StreamResponse
    {
        return $this->makeHttpRequest($uri, "DELETE", [], $queryParams);
    }

    /**
     * @throws StreamException
     */
    public function patch(string $uri, array $data, array $queryParams = []): StreamResponse
    {
        return $this->makeHttpRequest($uri, "PATCH", $data, $queryParams);
    }

    /**
     * @throws StreamException
     */
    public function post(string $uri, $data, array $queryParams = []): StreamResponse
    {
        return $this->makeHttpRequest($uri, "POST", $data, $queryParams);
    }

    /**
     * @throws StreamException
     */
    public function put(string $uri, array $data, array $queryParams = []): StreamResponse
    {
        return $this->makeHttpRequest($uri, "PUT", $data, $queryParams);
    }

    /** @link https://getstream.io/chat/docs/php/app_setting_overview/?language=php
     * @throws StreamException
     */
    public function getAppSettings(): StreamResponse
    {
        return $this->get("app");
    }

    /** @link https://getstream.io/chat/docs/php/app_setting_overview/?language=php
     * @throws StreamException
     */
    public function updateAppSettings(array $settings): StreamResponse
    {
        return $this->patch("app", $settings);
    }

    /** Sends a test push.
     * @link https://getstream.io/chat/docs/php/push_introduction/?language=php
     * @throws StreamException
     */
    public function checkPush(array $pushSettings): StreamResponse
    {
        return $this->post("check_push", $pushSettings);
    }

    /** Sends a test SQS push.
     * @link https://getstream.io/chat/docs/php/push_introduction/?language=php
     * @throws StreamException
     */
    public function checkSqs(array $sqsSettings): StreamResponse
    {
        return $this->post("check_sqs", $sqsSettings);
    }

    /** @link https://getstream.io/chat/docs/php/update_users/?language=php
     * @throws StreamException
     */
    public function upsertUsers(array $users): StreamResponse
    {
        $user_array = [];
        foreach ($users as $user) {
            $user_array[$user["id"]] = $user;
        }
        return $this->post("users", ["users" => $user_array]);
    }

    /** @link https://getstream.io/chat/docs/php/update_users/?language=php
     * @throws StreamException
     */
    public function upsertUser(array $user): StreamResponse
    {
        return $this->upsertUsers([$user]);
    }

    /** @link https://getstream.io/chat/docs/php/update_users/?language=php
     * @deprecated use `$client->upsertUsers` instead
     * @throws StreamException
     */
    public function updateUsers(array $users): StreamResponse
    {
        return $this->upsertUsers($users);
    }

    /** @link https://getstream.io/chat/docs/php/update_users/?language=php
     * @deprecated use `$client->upsertUser` instead
     * @throws StreamException
     */
    public function updateUser(array $user): StreamResponse
    {
        return $this->upsertUsers([$user]);
    }

    /** @link https://getstream.io/chat/docs/php/update_users/?language=php
     * @throws StreamException
     */
    public function partialUpdateUsers(array $partialUpdates): StreamResponse
    {
        return $this->patch("users", ["users" => $partialUpdates]);
    }

    /** @link https://getstream.io/chat/docs/php/update_users/?language=php
     * @throws StreamException
     */
    public function partialUpdateUser(array $partialUpdate): StreamResponse
    {
        return $this->partialUpdateUsers([$partialUpdate]);
    }

    /** @link https://getstream.io/chat/docs/php/update_users/?language=php
     * @throws StreamException
     */
    public function deleteUser(string $userId, array $options = []): StreamResponse
    {
        return $this->delete("users/" . $userId, $options);
    }

    /** @link https://getstream.io/chat/docs/php/update_users/?language=php
     * @throws StreamException
     */
    public function deleteUsers(array $userIds, array $options = null): StreamResponse
    {
        if ($options === null) {
            $options = (object)[];
        }
        $options["user_ids"] = $userIds;
        return $this->post("users/delete", $options);
    }

    /** @link https://getstream.io/chat/docs/php/channel_delete/?language=php
     * @throws StreamException
     */
    public function deleteChannels(array $cids, array $options = null): StreamResponse
    {
        if ($options === null) {
            $options = (object)[];
        }
        $options["cids"] = $cids;
        return $this->post("channels/delete", $options);
    }

    /** Creates a guest user.
     * @link https://getstream.io/chat/docs/php/authless_users/?language=php
     * @throws StreamException
     */
    public function setGuestUser(array $guestRequest): StreamResponse
    {
        return $this->post("guest", $guestRequest);
    }

    /** @link https://getstream.io/chat/docs/php/update_users/?language=php
     * @throws StreamException
     */
    public function deactivateUser(string $userId, array $options = null): StreamResponse
    {
        if ($options === null) {
            $options = (object)[];
        }
        return $this->post("users/" . $userId . "/deactivate", $options);
    }

    /** @link https://getstream.io/chat/docs/php/update_users/?language=php
     * @throws StreamException
     */
    public function reactivateUser(string $userId, array $options = null): StreamResponse
    {
        if ($options === null) {
            $options = (object)[];
        }
        return $this->post("users/" . $userId . "/reactivate", $options);
    }

    /** @link https://getstream.io/chat/docs/php/exporting_channels/?language=php#exporting-users
     * @throws StreamException
     */
    public function exportUser(string $userId, array $options = []): StreamResponse
    {
        return $this->get("users/" . $userId . "/export", $options);
    }

    /** @link https://getstream.io/chat/docs/php/moderation/?language=php
     * @throws StreamException
     */
    public function banUser(string $targetId, array $options = null): StreamResponse
    {
        if ($options === null) {
            $options = [];
        }
        $options["target_user_id"] = $targetId;
        return $this->post("moderation/ban", $options);
    }

    /** @link https://getstream.io/chat/docs/php/moderation/?language=php
     * @throws StreamException
     */
    public function unbanUser(string $targetId, array $options = null): StreamResponse
    {
        if ($options === null) {
            $options = [];
        }
        $options["target_user_id"] = $targetId;
        return $this->delete("moderation/ban", $options);
    }

    /** @link https://getstream.io/chat/docs/php/moderation/?language=php
     * @throws StreamException
     */
    public function shadowBan(string $targetId, array $options = null): StreamResponse
    {
        if ($options === null) {
            $options = [];
        }
        $options["shadow"] = true;
        return $this->banUser($targetId, $options);
    }

    /** @link https://getstream.io/chat/docs/php/moderation/?language=php
     * @throws StreamException
     */
    public function removeShadowBan(string $targetId, array $options = null): StreamResponse
    {
        if ($options === null) {
            $options = [];
        }
        $options["shadow"] = true;
        return $this->unbanUser($targetId, $options);
    }

    /** Queries banned users.
     * @link https://getstream.io/chat/docs/php/moderation/?language=php
     * @throws StreamException
     */
    public function queryBannedUsers(array $filterConditions, array $options = []): StreamResponse
    {
        $options["filter_conditions"] = $filterConditions;
        return $this->get("query_banned_users", ["payload" => json_encode($options)]);
    }

    /** @link https://getstream.io/chat/docs/php/send_message/?language=php#get-a-message
     * @throws StreamException
     */
    public function getMessage(string $messageId): StreamResponse
    {
        return $this->get("messages/" . $messageId);
    }

    /** @link https://getstream.io/chat/docs/php/moderation/?language=php
     * @throws StreamException
     */
    public function queryMessageFlags(array $filterConditions, array $options = []): StreamResponse
    {
        $options["filter_conditions"] = $filterConditions;
        return $this->get("moderation/flags/message", ["payload" => json_encode($options)]);
    }

    /** @link https://getstream.io/chat/docs/php/moderation/?language=php
     * @throws StreamException
     */
    public function flagMessage(string $targetId, array $options = null): StreamResponse
    {
        if ($options === null) {
            $options = [];
        }
        $options["target_message_id"] = $targetId;
        return $this->post("moderation/flag", $options);
    }

    /** @link https://getstream.io/chat/docs/php/moderation/?language=php
     * @throws StreamException
     */
    public function unFlagMessage(string $targetId, array $options = null): StreamResponse
    {
        if ($options === null) {
            $options = [];
        }
        $options["target_message_id"] = $targetId;
        return $this->post("moderation/unflag", $options);
    }

    /** @link https://getstream.io/chat/docs/php/moderation/?language=php
     * @throws StreamException
     */
    public function flagUser(string $targetId, array $options = null): StreamResponse
    {
        if ($options === null) {
            $options = [];
        }
        $options["target_user_id"] = $targetId;
        return $this->post("moderation/flag", $options);
    }

    /** @link https://getstream.io/chat/docs/php/moderation/?language=php
     * @throws StreamException
     */
    public function unFlagUser(string $targetId, array $options = null): StreamResponse
    {
        if ($options === null) {
            $options = [];
        }
        $options["target_user_id"] = $targetId;
        return $this->post("moderation/unflag", $options);
    }

    /** @link https://getstream.io/chat/docs/php/moderation/?language=php
     * @throws StreamException
     */
    public function muteUser(string $targetId, string $userId): StreamResponse
    {
        $options = [];
        $options["target_id"] = $targetId;
        $options["user_id"] = $userId;
        return $this->post("moderation/mute", $options);
    }

    /** @link https://getstream.io/chat/docs/php/moderation/?language=php
     * @throws StreamException
     */
    public function unmuteUser(string $targetId, string $userId): StreamResponse
    {
        $options = [];
        $options["target_id"] = $targetId;
        $options["user_id"] = $userId;
        return $this->post("moderation/unmute", $options);
    }

    /** @link https://getstream.io/chat/docs/rest/#channels-markchannelsread<
     * @throws StreamException
     */
    public function markAllRead(string $userId): StreamResponse
    {
        $options = [
            "user" => [
                "id" => $userId
            ]
        ];
        return $this->post("channels/read", $options);
    }

    /** @link https://getstream.io/chat/docs/php/pinned_messages/?language=php
     * @throws StreamException
     */
    public function pinMessage(string $messageId, string $userId, int $expiration = null): StreamResponse
    {
        $updates = [
            "set" => [
                "pinned" => true,
                "pin_expires" => $expiration,
            ]
        ];
        return $this->partialUpdateMessage($messageId, $updates, $userId);
    }

    /** @link https://getstream.io/chat/docs/php/pinned_messages/?language=php
     * @throws StreamException
     */
    public function unPinMessage(string $messageId, string $userId): StreamResponse
    {
        $updates = [
            "set" => [
                "pinned" => false,
            ]
        ];
        return $this->partialUpdateMessage($messageId, $updates, $userId);
    }

    /** @link https://getstream.io/chat/docs/php/send_message/?language=php#partial-update
     * @throws StreamException
     */
    public function partialUpdateMessage(string $messageId, array $updates, string $userId = null, array $options = null): StreamResponse
    {
        if ($options === null) {
            $options = [];
        }
        if ($userId !== null) {
            $options["user"] = ["id" => $userId];
        }
        $options = array_merge($options, $updates);
        return $this->put("messages/" . $messageId, $options);
    }

    /** @link https://getstream.io/chat/docs/php/send_message/?language=php
     * @throws StreamException
     */
    public function updateMessage(array $message): StreamResponse
    {
        try {
            $messageId = $message["id"];
        } catch (Exception $e) {
            throw new StreamException("A message must have an id");
        }
        $options = ["message" => $message];
        return $this->post("messages/" . $messageId, $options);
    }

    /** @link https://getstream.io/chat/docs/php/send_message/?language=php
     * @throws StreamException
     */
    public function deleteMessage(string $messageId, array $options = []): StreamResponse
    {
        return $this->delete("messages/" . $messageId, $options);
    }

    /** @link https://getstream.io/chat/docs/php/query_users/?language=php
     * @throws StreamException
     */
    public function queryUsers(array $filterConditions, array $sort = null, array $options = null): StreamResponse
    {
        if ($options === null) {
            $options = [];
        }
        $sortFields = [];
        if ($sort !== null) {
            foreach ($sort as $k => $v) {
                $sortFields[] = ["field" => $k, "direction" => $v];
            }
        }
        $options["filter_conditions"] = $filterConditions;
        $options["sort"] = $sortFields;
        return $this->get("users", ["payload" => json_encode($options)]);
    }

    /** @link https://getstream.io/chat/docs/php/query_channels/?language=php
     * @throws StreamException
     */
    public function queryChannels(array $filterConditions, array $sort = null, array $options = null): StreamResponse
    {
        if (!$filterConditions) {
            throw new StreamException("filterConditions can't be empty");
        }
        if ($options === null) {
            $options = [];
        }
        if (!in_array("state", $options)) {
            $options["state"] = true;
        }
        if (!in_array("watch", $options)) {
            $options["watch"] = false;
        }
        if (!in_array("presence", $options)) {
            $options["presence"] = false;
        }
        $sortFields = [];
        if ($sort !== null) {
            foreach ($sort as $k => $v) {
                $sortFields[] = ["field" => $k, "direction" => $v];
            }
        }
        $options["filter_conditions"] = $filterConditions;
        $options["sort"] = $sortFields;
        return $this->post("channels", $options);
    }

    /** @link https://getstream.io/chat/docs/php/channel_features/?language=php
     * @throws StreamException
     */
    public function createChannelType(array $data): StreamResponse
    {
        if ((!in_array("commands", $data)) || empty($data["commands"])) {
            $data["commands"] = ["all"];
        }
        return $this->post("channeltypes", $data);
    }

    /** @link https://getstream.io/chat/docs/php/channel_features/?language=php
     * @throws StreamException
     */
    public function getChannelType(string $channelTypeName): StreamResponse
    {
        return $this->get("channeltypes/" . $channelTypeName);
    }

    /** @link https://getstream.io/chat/docs/php/channel_features/?language=php
     * @throws StreamException
     */
    public function listChannelTypes(): StreamResponse
    {
        return $this->get("channeltypes");
    }

    /** @link https://getstream.io/chat/docs/php/channel_features/?language=php
     * @throws StreamException
     */
    public function updateChannelType(string $channelTypeName, array $settings): StreamResponse
    {
        return $this->put("channeltypes/" . $channelTypeName, $settings);
    }

    /** @link https://getstream.io/chat/docs/php/channel_features/?language=php
     * @throws StreamException
     */
    public function deleteChannelType(string $channelTypeName): StreamResponse
    {
        return $this->delete("channeltypes/" . $channelTypeName);
    }

    /** Return a client to interract with the channel.
     * @throws StreamException
     */
    public function Channel(string $channelTypeName, ?string $channelId, array $data = null): Channel
    {
        return new Channel($this, $channelTypeName, $channelId, $data);
    }

    /** @deprecated method: use `$client->Channel` instead
     * @throws StreamException
     */
    public function getChannel(string $channelTypeName, string $channelId, array $data = null): Channel
    {
        return $this->Channel($channelTypeName, $channelId, $data);
    }

    /** Creates a blocklist.
     * @link https://getstream.io/chat/docs/php/block_lists/?language=php
     * @throws StreamException
     */
    public function createBlocklist(array $blocklist): StreamResponse
    {
        return $this->post("blocklists", $blocklist);
    }

    /** Lists all blocklists.
     * @link https://getstream.io/chat/docs/php/block_lists/?language=php
     * @throws StreamException
     */
    public function listBlocklists(): StreamResponse
    {
        return $this->get("blocklists");
    }

    /** Returns a blocklist.
     * @link https://getstream.io/chat/docs/php/block_lists/?language=php
     * @throws StreamException
     */
    public function getBlocklist(string $name): StreamResponse
    {
        return $this->get("blocklists/${name}");
    }

    /** Updates a blocklist.
     * @link https://getstream.io/chat/docs/php/block_lists/?language=php
     * @throws StreamException
     */
    public function updateBlocklist(string $name, array $blocklist): StreamResponse
    {
        return $this->put("blocklists/${name}", $blocklist);
    }

    /** Deletes a blocklist.
     * @link https://getstream.io/chat/docs/php/block_lists/?language=php
     * @throws StreamException
     */
    public function deleteBlocklist(string $name): StreamResponse
    {
        return $this->delete("blocklists/${name}");
    }

    /** Creates a command.
     * @link https://getstream.io/chat/docs/php/custom_commands_webhook/?language=php
     * @throws StreamException
     */
    public function createCommand(array $command): StreamResponse
    {
        return $this->post("commands", $command);
    }

    /** Lists all commands.
     * @link https://getstream.io/chat/docs/php/custom_commands_webhook/?language=php
     * @throws StreamException
     */
    public function listCommands(): StreamResponse
    {
        return $this->get("commands");
    }

    /** Returns a command.
     * @link https://getstream.io/chat/docs/php/custom_commands_webhook/?language=php
     * @throws StreamException
     */
    public function getCommand(string $name): StreamResponse
    {
        return $this->get("commands/${name}");
    }

    /** Updates a command.
     * @link https://getstream.io/chat/docs/php/custom_commands_webhook/?language=php
     * @throws StreamException
     */
    public function updateCommand(string $name, array $command): StreamResponse
    {
        return $this->put("commands/${name}", $command);
    }

    /** Deletes a command.
     * @link https://getstream.io/chat/docs/php/custom_commands_webhook/?language=php
     * @throws StreamException
     */
    public function deleteCommand(string $name): StreamResponse
    {
        return $this->delete("commands/${name}");
    }

    /**
     * @link https://getstream.io/chat/docs/php/push_devices/?language=php
     * @throws StreamException
     */
    public function addDevice(string $deviceId, string $pushProvider, string $userId): StreamResponse
    {
        $data = [
            "id" => $deviceId,
            "push_provider" => $pushProvider,
            "user_id" => $userId,
        ];
        return $this->post("devices", $data);
    }

    /**
     * @link https://getstream.io/chat/docs/php/push_devices/?language=php
     * @throws StreamException
     */
    public function deleteDevice(string $deviceId, string $userId): StreamResponse
    {
        $data = [
            "id" => $deviceId,
            "user_id" => $userId,
        ];
        return $this->delete("devices", $data);
    }

    /**
     * @link https://getstream.io/chat/docs/php/push_devices/?language=php
     * @throws StreamException
     */
    public function getDevices(string $userId): StreamResponse
    {
        $data = [
            "user_id" => $userId,
        ];
        return $this->get("devices", $data);
    }

    /**
     * @link https://getstream.io/chat/docs/php/push_devices/?language=php
     * @throws StreamException
     */
    public function revokeTokens(DateTime $before): StreamResponse
    {
        if ($before instanceof DateTime) {
            $before = $before->format(DateTime::ATOM);
        }
        $settings = [
            "revoke_tokens_issued_before" => $before
        ];
        return $this->updateAppSettings($settings);
    }

    /**
     * @link https://getstream.io/chat/docs/php/tokens_and_authentication/?language=php
     * @param DateTime|int $before
     * @throws StreamException
     */
    public function revokeUserToken(string $userId, $before): StreamResponse
    {
        return $this->revokeUsersToken([$userId], $before);
    }

    /**
     * @link https://getstream.io/chat/docs/php/tokens_and_authentication/?language=php
     * @param DateTime|int $before
     * @throws StreamException
     */
    public function revokeUsersToken(array $userIDs, $before): StreamResponse
    {
        if ($before instanceof DateTime) {
            $before = $before->format(DateTime::ATOM);
        }
        $updates = [];
        foreach ($userIDs as $userID) {
            array_push($updates, [
                "id" => $userID,
                "set" => [
                    "revoke_tokens_issued_before" => $before
                ]
            ]);
        }
        return $this->partialUpdateUsers($updates);
    }

    /**
     * @link https://getstream.io/chat/docs/php/rate_limits/?language=php
     * @throws StreamException
     */
    public function getRateLimits(bool $serverSide = false, bool $android = false, bool $ios = false, bool $web = false, array $endpoints = null): StreamResponse
    {
        $data = [];
        if ($serverSide) {
            $data["server_side"] = "true";
        }
        if ($android) {
            $data["android"] = "true";
        }
        if ($ios) {
            $data["ios"] = "true";
        }
        if ($web) {
            $data["web"] = "true";
        }
        if ($endpoints !== null && is_array($endpoints)) {
            $data["endpoints"] = implode(",", $endpoints);
        }
        return $this->get("rate_limits", $data);
    }

    /**
     * @throws StreamException
     */
    public function verifyWebhook(string $requestBody, string $XSignature): bool
    {
        $signature = hash_hmac("sha256", $requestBody, $this->apiSecret);

        return $signature === $XSignature;
    }

    /** @link https://getstream.io/chat/docs/php/search/?language=php
     * @throws StreamException
     */
    public function search(array $filterConditions, $query, array $options = null): StreamResponse
    {
        if ($options === null) {
            $options = [];
        }

        if (array_key_exists('offset', $options) && $options['offset'] > 0) {
            if (array_key_exists('next', $options) || array_key_exists('sort', $options)) {
                throw new StreamException("Cannot use offset with next or sort parameters");
            }
        }

        $options['filter_conditions'] = $filterConditions;
        if (is_string($query)) {
            $options['query'] = $query;
        } else {
            $options['message_filter_conditions'] = $query;
        }

        $sortFields = [];
        if (array_key_exists('sort', $options)) {
            $sort = $options['sort'];
            foreach ($sort as $k => $v) {
                $sortFields[] = ["field" => $k, "direction" => $v];
            }
        }
        $options['sort'] = $sortFields;
        return $this->get("search", ["payload" => json_encode($options)]);
    }

    /** @link https://getstream.io/chat/docs/php/file_uploads/?language=php
     * @throws StreamException
     */
    public function sendFile(string $uri, string $url, string $name, array $user, string $contentType = null): StreamResponse
    {
        if ($contentType === null) {
            $contentType = 'application/octet-stream';
        }
        $multipart = [
            [
                'name' => 'file',
                'contents' => file_get_contents($url),
                'filename' => $name,
                // let guzzle handle the content-type
                // 'headers'  => [ 'Content-Type' => $contentType]
            ],
            [
                'name'     => 'user',
                'contents' => json_encode($user),
                // let guzzle handle the content-type
                // 'headers'  => ['Content-Type' => 'application/json']
            ]
        ];
        return $this->makeHttpRequest($uri, 'POST', [], [], $multipart);
    }

    /** Runs a message command action.
     * @link https://getstream.io/chat/docs/rest/#messages-runmessageaction
     * @throws StreamException
     */
    public function sendMessageAction(string $messageId, string $userId, array $formData)
    {
        return $this->post("messages/${messageId}/action", ["user_id" => $userId, "form_data" => $formData]);
    }

    /** @link https://getstream.io/chat/docs/php/user_permissions/?language=php
     * @throws StreamException
     */
    public function listRoles(): StreamResponse
    {
        return $this->get("roles");
    }

    /** @link https://getstream.io/chat/docs/php/user_permissions/?language=php
     * @throws StreamException
     */
    public function listPermissions(): StreamResponse
    {
        return $this->get("permissions");
    }

    /** @link https://getstream.io/chat/docs/php/user_permissions/?language=php
     * @throws StreamException
     */
    public function getPermission(string $id): StreamResponse
    {
        return $this->get("permissions/${id}");
    }

    /** @link https://getstream.io/chat/docs/php/user_permissions/?language=php
     * @throws StreamException
     */
    public function createRole(string $name): StreamResponse
    {
        $data = [
            'name' => $name,
        ];
        return $this->post("roles", $data);
    }

    /** @link https://getstream.io/chat/docs/php/user_permissions/?language=php
     * @throws StreamException
     */
    public function deleteRole(string $name): StreamResponse
    {
        return $this->delete("roles/${name}");
    }

    /** Translates a message to a language.
     * @link https://getstream.io/chat/docs/php/translation/?language=php
     * @throws StreamException
     */
    public function translateMessage(string $messageId, string $language): StreamResponse
    {
        return $this->post("messages/${messageId}/translate", ["language" => $language]);
    }

    /**
     * Schedules channel export task for list of channels
     * @link https://getstream.io/chat/docs/php/exporting_channels/?language=php
     * @param $requests array of requests for channel export. Each of them should contain `type` and `id` fields and optionally `messages_since` and `messages_until`
     * @param $options array of options
     * @return StreamResponse returns task ID that you can use to get export status (see getTask method)
     */
    public function exportChannels(array $requests, array $options = []): StreamResponse
    {
        $data = array_merge($options, [
            'channels' => $requests,
        ]);
        return $this->post("export_channels", $data);
    }

    /**
     * Schedules channel export task for a single channel
     * @link https://getstream.io/chat/docs/php/exporting_channels/?language=php
     * @param $request export channel request (see exportChannel)
     * @param $options array of options
     * @return StreamResponse returns task ID that you can use to get export status (see getTask method)
     */
    public function exportChannel(array $request, array $options = []): StreamResponse
    {
        return $this->exportChannels([$request], $options);
    }

    /**
     * Gets the status of a channel export task.
     * @link https://getstream.io/chat/docs/php/exporting_channels/?language=php
     */
    public function getExportChannelStatus(string $id): StreamResponse
    {
        return $this->get("export_channels/${id}");
    }

    /**
     * Returns task status
     * @link https://getstream.io/chat/docs/rest/#tasks-gettask
     */
    public function getTask(string $id): StreamResponse
    {
        return $this->get("tasks/{$id}");
    }

    /** @link https://getstream.io/chat/docs/php/custom_events/?language=php
     * @throws StreamException
     */
    public function sendUserCustomEvent(string $userId, array $event): StreamResponse
    {
        return $this->post("users/{$userId}/event", ["event" => $event]);
    }
}

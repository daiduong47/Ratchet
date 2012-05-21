<?php
namespace Ratchet\WebSocket;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Guzzle\Http\Message\RequestInterface;
use Ratchet\WebSocket\Guzzle\Http\Message\RequestFactory;

/**
 * The adapter to handle WebSocket requests/responses
 * This is a mediator between the Server and your application to handle real-time messaging through a web browser
 * @todo Separate this class into a two classes: Component and a protocol handler
 * @link http://ca.php.net/manual/en/ref.http.php
 * @link http://dev.w3.org/html5/websockets/
 */
class WsServer implements MessageComponentInterface {
    /**
     * Negotiates upgrading the HTTP connection to a WebSocket connection
     * It contains useful configuration properties and methods
     * @var HandshakeNegotiator
     * @note May not expose this in the future, may do through facade methods
     */
    public $handshaker;

    /**
     * Decorated component
     * @var Ratchet\MessageComponentInterface|WsServerInterface
     */
    protected $_decorating;

    /**
     * @var SplObjectStorage
     */
    protected $connections;

    /**
     * @var MessageParser
     */
    protected $messager;

    /**
     * For now, array_push accepted subprotocols to this array
     * @deprecated
     * @temporary
     */
    protected $acceptedSubProtocols = array();

    /**
     * Flag if we have checked the decorated component for sub-protocols
     * @var boolean
     */
    private $isSpGenerated = false;

    /**
     * @param Ratchet\MessageComponentInterface Your application to run with WebSockets
     */
    public function __construct(MessageComponentInterface $component) {
        mb_internal_encoding('UTF-8');

        $this->handshaker = new HandshakeNegotiator;
        $this->messager   = new MessageParser;

        $this->_decorating = $component;
        $this->connections = new \SplObjectStorage;
    }

    /**
     * {@inheritdoc}
     */
    public function onOpen(ConnectionInterface $conn) {
        $wsConn = new WsConnection($conn);

        $this->connections->attach($conn, $wsConn);

        $this->handshaker->onOpen($wsConn);

        $conn->WebSocket->established = false;
    }

    /**
     * {@inheritdoc}
     */
    public function onMessage(ConnectionInterface $from, $msg) {
        $conn = $this->connections[$from];

        if (true !== $conn->WebSocket->established) {
            if (null === ($response = $this->handshaker->onData($conn, $msg))) {
                return;
            }

            // This needs to be refactored later on, incorporated with routing
            if ('' !== ($agreedSubProtocols = $this->getSubProtocolString($from->WebSocket->request->getTokenizedHeader('Sec-WebSocket-Protocol', ',')))) {
                $response->setHeader('Sec-WebSocket-Protocol', $agreedSubProtocols);
            }

            $from->send((string)$response);

            if (101 != $response->getStatusCode()) {
                return $from->close();
            }

            $conn->WebSocket->established = true;

            return $this->_decorating->onOpen($conn);
        }

        if (null !== ($parsed = $this->messager->onData($conn, $msg))) {
            $this->_decorating->onMessage($conn, $parsed);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onClose(ConnectionInterface $conn) {
        $decor = $this->connections[$conn];
        $this->connections->detach($conn);

        // WS::onOpen is not called when the socket connects, it's call when the handshake is done
        // The socket could close before WS calls onOpen, so we need to check if we've "opened" it for the developer yet
        if ($decor->WebSocket->established) {
            $this->_decorating->onClose($decor);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onError(ConnectionInterface $conn, \Exception $e) {
        if ($conn->WebSocket->established) {
            $this->_decorating->onError($this->connections[$conn], $e);
        } else {
            $conn->close();
        }
    }

    /**
     * @param string
     * @return boolean
     */
    public function isSubProtocolSupported($name) {
        if (!$this->isSpGenerated) {
            if ($this->_decorating instanceof WsServerInterface) {
                $this->acceptedSubProtocols = array_flip($this->_decorating->getSubProtocols());
            }

            $this->isSpGenerated = true;
        }

        return array_key_exists($name, $this->acceptedSubProtocols);
    }

    /**
     * @param Traversable
     * @return string
     */
    protected function getSubProtocolString(\Traversable $requested = null) {
        if (null === $requested) {
            return '';
        }

        $string = '';

        foreach ($requested as $sub) {
            if ($this->isSubProtocolSupported($sub)) {
                $string .= $sub . ',';
            }
        }

        return mb_substr($string, 0, -1, 'ASCII');
    }
}
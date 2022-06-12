<?php

/**
 * Simple JSON-RPC interface.
 */
 
class JSON_IPC
{
	protected $ipcfile, $version;
	protected $id = 0;
	
	function __construct($ipcfile, $version="2.0")
	{
		$this->ipcfile = $ipcfile;
		$this->version = $version;
	}
	
	function request($method, $params=array())
	{
	    try {
            $data = array();
            $data['jsonrpc'] = $this->version;
            $data['id'] = $this->id++;
            $data['method'] = $method;
            $data['params'] = $params;

            $msg = json_encode($data);

            $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
            socket_connect($socket, $this->ipcfile);

            socket_send($socket, $msg, strlen($msg), MSG_EOF);

            $lbc = 0;
            $rbc = 0;
            $nextcheck = 0;
            $buf = '';
            $instrflag = false;
            // 32kbyteまで許容
            while (($bytesrecvd = socket_recv($socket, $buf, 32 * 1024, MSG_PEEK))) {

                // 取得した中に{と}が同量含まれていれば適正なjsonとみなし受信終了する
                for ($i = $nextcheck; $i < $bytesrecvd; $i++) {
                    // 文字列内の{, }はカウントしない
                    if ($instrflag) {
                        // 文字列が終了したか
                        if ($buf{$i} === '"') {
                            $instrflag = false;
                        }
                    } else {
                        // 文字列が始まるかどうか
                        if ($buf{$i} === '"') {
                            // 文字列が始まった
                            $instrflag = true;
                        } else {
                            // 実際にカウントする
                            if ($buf{$i} === '{') {
                                $lbc++;
                            } else if ($buf{$i} === '}') {
                                $rbc++;
                            }
                        }
                    }
                }

                if ($lbc === $rbc) {
                    // 完全なjsonをレシーブしたので終了
                    break;
                }

                $nextcheck = $bytesrecvd;
            }

            // 最後の終了が正常か確認
            if($bytesrecvd !== false)
            {
                $formatted = $this->format_response($buf);

                if(isset($formatted->error))
                {
                    throw new RPCException($formatted->error->message, $formatted->error->code);
                }
                else
                {
                    return $formatted;
                }
            }
            else
            {
                throw new RPCException("Server did not respond");
            }
        } finally {
            socket_close($socket);
        }
	}

	function format_response($response)
	{
		return @json_decode($response);
	}
}

class RPCException extends Exception
{
    public function __construct($message, $code = 0, Exception $previous = null) 
    {
        parent::__construct($message, $code, $previous);
    }
    
    public function __toString() 
    {
        return __CLASS__ . ": ".(($this->code > 0)?"[{$this->code}]:":"")." {$this->message}\n";
    }
}
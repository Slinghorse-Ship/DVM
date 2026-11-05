#!/usr/bin/env python3
"""One loopback-only TLS SMTP exchange; all messages are synthetic and stay private."""
import base64
import hashlib
import json
import pathlib
import socket
import ssl
import sys

root = pathlib.Path(sys.argv[1])
mode = sys.argv[2]
if mode not in {"accept", "drop"} or not root.is_dir():
    raise RuntimeError("Owned SMTP protocol fixture required")
tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
tls.load_cert_chain(root / "certificate.pem", root / "key.pem")
with socket.socket() as listener:
    listener.bind(("127.0.0.1", 0))
    listener.listen(1)
    listener.settimeout(45)
    print(listener.getsockname()[1], flush=True)
    connection, address = listener.accept()
    if address[0] != "127.0.0.1":
        raise RuntimeError("Loopback required")
    connection.settimeout(20)
    encrypted = authenticated = False
    sender = recipient = ""

    def line():
        data = bytearray()
        while not data.endswith(b"\r\n"):
            chunk = connection.recv(1)
            if not chunk:
                raise RuntimeError("Owned SMTP peer disconnected")
            data.extend(chunk)
            if len(data) > 16000:
                raise RuntimeError("Owned bounded SMTP line required")
        return bytes(data)

    connection.sendall(b"220 localhost owned synthetic SMTP\r\n")
    while True:
        command = line()
        if command.startswith(b"EHLO "):
            connection.sendall(b"250-localhost\r\n250-STARTTLS\r\n250 AUTH PLAIN LOGIN\r\n")
        elif command == b"STARTTLS\r\n" and not encrypted:
            connection.sendall(b"220 Ready\r\n")
            connection = tls.wrap_socket(connection, server_side=True)
            encrypted = True
        elif command.startswith(b"AUTH PLAIN ") and encrypted:
            credential = base64.b64decode(command.split()[2], validate=True)
            authenticated = credential.count(b"\0") == 2
            connection.sendall(b"235 Authenticated\r\n")
        elif command.startswith(b"AUTH LOGIN") and encrypted:
            connection.sendall(b"334 VXNlcm5hbWU6\r\n")
            user = base64.b64decode(line().strip(), validate=True)
            connection.sendall(b"334 UGFzc3dvcmQ6\r\n")
            password = base64.b64decode(line().strip(), validate=True)
            authenticated = bool(user and password)
            connection.sendall(b"235 Authenticated\r\n")
        elif command.startswith(b"MAIL FROM:") and authenticated:
            sender = command.decode().strip()[10:]
            connection.sendall(b"250 OK\r\n")
        elif command.startswith(b"RCPT TO:") and authenticated:
            recipient = command.decode().strip()[8:]
            connection.sendall(b"250 OK\r\n")
        elif command == b"DATA\r\n" and encrypted and authenticated:
            connection.sendall(b"354 Continue\r\n")
            data = bytearray()
            while not data.endswith(b"\r\n.\r\n"):
                chunk = connection.recv(1)
                if not chunk:
                    raise RuntimeError("Owned SMTP peer disconnected during DATA")
                data.extend(chunk)
                if len(data) > 16 * 1024 * 1024:
                    raise RuntimeError("Owned bounded SMTP message required")
            message = bytes(data[:-5]).replace(b"\r\n..", b"\r\n.")
            result = {"tls": encrypted, "authenticated": authenticated, "sender": sender,
                      "recipient": recipient, "mime_sha256": hashlib.sha256(message).hexdigest(),
                      "bytes": len(message), "mode": mode}
            (root / "result.json").write_text(json.dumps(result))
            if mode == "drop":
                connection.close()
                break
            connection.sendall(b"250 OK\r\n")  # Deliberately no provider queue id.
        elif command == b"QUIT\r\n":
            connection.sendall(b"221 Bye\r\n")
            connection.close()
            break
        elif command == b"RSET\r\n":
            connection.sendall(b"250 OK\r\n")
        else:
            connection.sendall(b"550 Owned fixture command rejected\r\n")

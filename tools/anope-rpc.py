#!/usr/bin/env python3
"""Tiny JSON-RPC client for the Anope test instance. Usage: rpc.py METHOD [PARAM...]"""
import base64, json, sys, urllib.request

URL = "http://127.0.0.1:8080/jsonrpc"
# Anope base64-decodes the bearer value before comparing it with the configured token.
TOKEN = base64.b64encode(b"ooQu7thomahoo5Ee").decode()

def call(method, params):
    body = json.dumps({"jsonrpc": "2.0", "id": 1, "method": method, "params": params}).encode()
    req = urllib.request.Request(URL, data=body, headers={
        "Content-Type": "application/json", "Authorization": "Bearer " + TOKEN})
    with urllib.request.urlopen(req, timeout=10) as r:
        return json.loads(r.read().decode())

if __name__ == "__main__":
    out = call(sys.argv[1], sys.argv[2:])
    print(json.dumps(out.get("result", out), indent=1, ensure_ascii=False))

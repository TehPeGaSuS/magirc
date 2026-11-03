#!/usr/bin/env python3
"""Drive a few test clients against a local UnrealIRCd + Anope 2.1 so chanstats and
rpc_data have something to show. Keeps the clients connected until killed."""
import re, socket, sys, time

HOST, PORT = "127.0.0.1", 6667
CHAN = "#magtest"
PASS = "MagircTest1234"

class Client:
    def __init__(self, nick):
        self.nick = nick
        self.s = socket.create_connection((HOST, PORT))
        self.s.settimeout(0.5)
        self.buf = b""
        self.send(f"NICK {nick}")
        self.send(f"USER {nick.lower()} 0 * :MagIRC test {nick}")
        self.wait(r" 001 ")

    def send(self, line):
        self.s.sendall((line + "\r\n").encode())

    def lines(self):
        try:
            data = self.s.recv(65536)
        except socket.timeout:
            data = b""
        self.buf += data
        while b"\r\n" in self.buf:
            line, self.buf = self.buf.split(b"\r\n", 1)
            line = line.decode(errors="replace")
            if line.startswith("PING"):
                self.send("PONG" + line[4:])
            yield line

    def wait(self, pattern, timeout=8):
        end = time.time() + timeout
        while time.time() < end:
            for line in self.lines():
                if re.search(pattern, line):
                    return line
        return None

    def pump(self, seconds):
        end = time.time() + seconds
        while time.time() < end:
            for _ in self.lines():
                pass

    def ns(self, text):
        self.send(f"PRIVMSG NickServ :{text}")

    def cs(self, text):
        self.send(f"PRIVMSG ChanServ :{text}")

    def register(self):
        # Accounts already exist from the earlier runs: identify straight away so the
        # nick-protection timer does not rename us, then confirm if still pending.
        self.ns(f"IDENTIFY {PASS}")
        line = self.wait(r"now identified|Password accepted|isn't registered|is not registered", 8) or ""
        if "registered" in line and "now" not in line:
            self.ns(f"REGISTER {PASS} {self.nick.lower()}@example.org")
            line = self.wait(r"CONFIRM REGISTER|must wait", 10) or ""
            m = re.search(r"CONFIRM REGISTER (\S+?)\x02?\s*\.?$", line)
            if m:
                self.ns(f"CONFIRM REGISTER {m.group(1)}")
                self.wait(r"confirmed", 8)
        code = None  # paste a pending CONFIRM REGISTER code here if an account was left unconfirmed
        if code:
            self.ns(f"CONFIRM REGISTER {code}")
            self.wait(r"confirmed|no registration confirmation pending|already", 8)
        self.pump(1)


def main():
    alice = Client("Alice")
    alice.register()
    bob = Client("Bob")
    bob.register()
    carol = Client("Carol")  # unregistered, so no per-nick stats

    for c in (alice, bob, carol):
        c.send(f"JOIN {CHAN}")
    alice.wait(r"JOIN", 5)

    alice.cs(f"REGISTER {CHAN} MagIRC test channel")
    alice.wait(r"registered under|already registered", 8)
    alice.cs(f"SET CHANSTATS {CHAN} ON")
    alice.wait(r"Chanstats statistics are now|chanstats", 5)
    alice.ns("SET CHANSTATS ON")
    bob.ns("SET CHANSTATS ON")
    alice.pump(1); bob.pump(1)

    alice.send(f"TOPIC {CHAN} :MagIRC 2 test channel :)")
    alice.send(f"MODE {CHAN} +nt")
    for i in range(8):
        alice.send(f"PRIVMSG {CHAN} :hello number {i} from alice :)")
        bob.send(f"PRIVMSG {CHAN} :bob replies {i} :( no wait :D")
        carol.send(f"PRIVMSG {CHAN} :carol here {i}")
    alice.send(f"PRIVMSG {CHAN} :\x01ACTION waves at everyone\x01")
    bob.send(f"PRIVMSG {CHAN} :\x01ACTION waves back\x01")
    alice.send(f"KICK {CHAN} Carol :testing kicks")
    time.sleep(1)
    carol.send(f"JOIN {CHAN}")
    alice.send("AWAY :gone fishing")
    print("setup done; clients stay connected", flush=True)
    while True:
        for c in (alice, bob, carol):
            c.pump(0.2)
        time.sleep(1)


if __name__ == "__main__":
    main()

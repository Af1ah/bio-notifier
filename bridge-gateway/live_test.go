package main

import (
	"bytes"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/x509"
	"encoding/base64"
	"encoding/binary"
	"encoding/json"
	"encoding/pem"
	"fmt"
	"github.com/gorilla/websocket"
	"io"
	"net/http"
	"os"
	"os/exec"
	"strings"
	"testing"
	"time"
)

// Explicit opt-in: uses a disposable registry record, public TLS, and simulated device traffic.
func TestLivePublicPairAndSignedTunnel(t *testing.T) {
	file := os.Getenv("BIOMATRIX_LIVE_FIXTURE")
	if file == "" {
		t.Skip("live test not requested")
	}
	b, e := os.ReadFile(file)
	if e != nil {
		t.Fatal(e)
	}
	var fixture struct{ ID, Code string }
	json.Unmarshal(b, &fixture)
	key, e := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if e != nil {
		t.Fatal(e)
	}
	der, _ := x509.MarshalPKIXPublicKey(&key.PublicKey)
	pub := string(pem.EncodeToMemory(&pem.Block{Type: "PUBLIC KEY", Bytes: der}))
	input, _ := json.Marshal(map[string]string{"code": fixture.Code, "public_key": pub, "hardware_id": "temporary-gateway-test"})
	client := &http.Client{Timeout: 20 * time.Second}
	resp, e := client.Post("https://noti.aflahdev.in/api/bridges/pair", "application/json", bytes.NewReader(input))
	if e != nil {
		t.Fatal(e)
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		t.Fatalf("public pairing status %d", resp.StatusCode)
	}
	var paired struct {
		ID  string `json:"bridge_id"`
		URL string `json:"websocket_url"`
	}
	if e = json.NewDecoder(resp.Body).Decode(&paired); e != nil || paired.ID != fixture.ID {
		t.Fatal("pair response", e)
	}
	ws, _, e := websocket.DefaultDialer.Dial(paired.URL, nil)
	if e != nil {
		t.Fatal(e)
	}
	defer ws.Close()
	ws.SetReadDeadline(time.Now().Add(15 * time.Second))
	var challenge map[string]string
	if e = ws.ReadJSON(&challenge); e != nil {
		t.Fatal(e)
	}
	hash := sha256.Sum256([]byte("biomatrix-bridge-v1\n" + paired.ID + "\n" + challenge["nonce"]))
	r, s, e := ecdsa.Sign(rand.Reader, key, hash[:])
	if e != nil {
		t.Fatal(e)
	}
	raw := make([]byte, 64)
	r.FillBytes(raw[:32])
	s.FillBytes(raw[32:])
	ws.WriteJSON(map[string]string{"op": "auth", "bridge_id": paired.ID, "signature": base64.StdEncoding.EncodeToString(raw)})
	var ready map[string]interface{}
	if e = ws.ReadJSON(&ready); e == nil && ready["op"] == "pending" {
		if output, err := exec.Command("php", "/tmp/bridge_live_fixture.php", "approve").CombinedOutput(); err != nil {
			t.Fatalf("fixture approval failed: %s", output)
		}
		ws.Close()
		ws, _, e = websocket.DefaultDialer.Dial(paired.URL, nil)
		if e != nil {
			t.Fatal(e)
		}
		defer ws.Close()
		if e = ws.ReadJSON(&challenge); e != nil {
			t.Fatal(e)
		}
		hash = sha256.Sum256([]byte("biomatrix-bridge-v1\n" + paired.ID + "\n" + challenge["nonce"]))
		r, s, e = ecdsa.Sign(rand.Reader, key, hash[:])
		if e != nil {
			t.Fatal(e)
		}
		r.FillBytes(raw[:32])
		s.FillBytes(raw[32:])
		ws.WriteJSON(map[string]string{"op": "auth", "bridge_id": paired.ID, "signature": base64.StdEncoding.EncodeToString(raw)})
		e = ws.ReadJSON(&ready)
	}
	if e != nil || ready["op"] != "ready" {
		t.Fatal("signed public handshake", e)
	}
	env, e := os.ReadFile("/home/aflah/projects/biomatrix/bridge-gateway/.env")
	if e != nil {
		t.Fatal(e)
	}
	secret := ""
	for _, line := range strings.Split(string(env), "\n") {
		if strings.HasPrefix(line, "BRIDGE_GATEWAY_KEY=") {
			secret = strings.TrimPrefix(line, "BRIDGE_GATEWAY_KEY=")
		}
	}
	target := target{BridgeID: paired.ID, TenantID: "0c93ecb4-b72a-4528-b12d-659dc44693c0", DeviceID: "verification", IP: "192.168.29.74", Port: 80}
	body, _ := json.Marshal(target)
	request, _ := http.NewRequest("POST", "http://127.0.0.1:8072/endpoint", bytes.NewReader(body))
	request.Header.Set("X-Bridge-Gateway-Key", secret)
	resp, e = client.Do(request)
	if e != nil {
		t.Fatal(e)
	}
	var endpoint struct{ Port int }
	json.NewDecoder(resp.Body).Decode(&endpoint)
	resp.Body.Close()
	if resp.StatusCode != 200 || endpoint.Port == 0 {
		t.Fatalf("private endpoint %d", resp.StatusCode)
	}
	go func() {
		requests := map[uint32][]byte{}
		for {
			kind, p, e := ws.ReadMessage()
			if e != nil {
				return
			}
			if kind == websocket.TextMessage {
				var v struct {
					Op     string
					Stream uint32
				}
				json.Unmarshal(p, &v)
				if v.Op == "open" {
					ws.WriteJSON(map[string]interface{}{"op": "opened", "stream": v.Stream})
				}
				continue
			}
			if len(p) < 5 {
				continue
			}
			id := binary.BigEndian.Uint32(p[1:])
			if p[0] == dataFrame {
				requests[id] = append(requests[id], p[5:]...)
				credit := make([]byte, 4)
				binary.BigEndian.PutUint32(credit, uint32(len(p)-5))
				ws.WriteMessage(websocket.BinaryMessage, frame(creditFrame, id, credit))
				if bytes.Contains(requests[id], []byte("\r\n\r\n")) {
					ws.WriteMessage(websocket.BinaryMessage, frame(dataFrame, id, []byte("HTTP/1.1 200 OK\r\nContent-Length: 20\r\nConnection: close\r\n\r\npublic-tunnel-passed")))
					ws.WriteMessage(websocket.BinaryMessage, frame(halfFrame, id, nil))
					delete(requests, id)
				}
			}
		}
	}()
	resp, e = client.Get("http://127.0.0.1:" + fmt.Sprint(endpoint.Port) + "/test")
	if e != nil {
		t.Fatal(e)
	}
	output, e := io.ReadAll(resp.Body)
	resp.Body.Close()
	if e != nil || string(output) != "public-tunnel-passed" {
		t.Fatal("public forwarding failed", len(output), e)
	}
}

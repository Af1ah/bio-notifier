package main

import (
	"bytes"
	"encoding/binary"
	"encoding/json"
	"fmt"
	"github.com/gorilla/websocket"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"
)

func testGateway(t *testing.T) (*gateway, *httptest.Server) {
	t.Helper()
	registry := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("X-Bridge-Gateway-Key") != strings.Repeat("k", 64) {
			w.WriteHeader(403)
			return
		}
		var v map[string]string
		json.NewDecoder(r.Body).Decode(&v)
		if strings.HasSuffix(r.URL.Path, "/auth") && v["signature"] != "test-proof:"+v["nonce"] {
			w.WriteHeader(403)
			return
		}
		if v["bridge_id"] == "pending-bridge" {
			writeJSON(w, 409, map[string]string{"status": "pending"})
			return
		}
		writeJSON(w, 200, map[string]string{"bridge_id": v["bridge_id"], "tenant_id": "tenant-a"})
	}))
	g := &gateway{key: strings.Repeat("k", 64), app: registry.URL, domain: "example.test", agents: map[string]*agent{}, endpoints: map[string]net.Listener{}, sessions: map[string]*webSession{}, httpClient: &http.Client{Timeout: time.Second}, handshakes: make(chan struct{}, 64)}
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/agent" {
			g.serveAgent(w, r)
		} else {
			g.web(w, r)
		}
	}))
	t.Cleanup(func() {
		g.mu.Lock()
		for _, a := range g.agents {
			a.close()
		}
		for _, l := range g.endpoints {
			l.Close()
		}
		g.mu.Unlock()
		server.Close()
		registry.Close()
	})
	return g, server
}
func connectTestAgent(t *testing.T, s *httptest.Server, valid bool) *websocket.Conn {
	t.Helper()
	ws, _, err := websocket.DefaultDialer.Dial("ws"+strings.TrimPrefix(s.URL, "http")+"/agent", nil)
	if err != nil {
		t.Fatal(err)
	}
	var msg map[string]interface{}
	if err = ws.ReadJSON(&msg); err != nil {
		t.Fatal(err)
	}
	proof := "invalid"
	if valid {
		proof = "test-proof:" + msg["nonce"].(string)
	}
	ws.WriteJSON(map[string]string{"op": "auth", "bridge_id": "bridge-a", "signature": proof})
	if valid {
		if err = ws.ReadJSON(&msg); err != nil || msg["op"] != "ready" {
			t.Fatalf("ready: %v %v", msg, err)
		}
	}
	return ws
}
func frame(kind byte, id uint32, b []byte) []byte {
	p := make([]byte, 5+len(b))
	p[0] = kind
	binary.BigEndian.PutUint32(p[1:], id)
	copy(p[5:], b)
	return p
}
func targetFor(i int) target {
	return target{BridgeID: "bridge-a", TenantID: "tenant-a", DeviceID: fmt.Sprint(i), IP: fmt.Sprintf("192.168.29.%d", i+20), Port: 80, WebHost: "matrix-test.example.test"}
}
func TestAuthenticationAndTenantIsolation(t *testing.T) {
	g, s := testGateway(t)
	bad := connectTestAgent(t, s, false)
	bad.SetReadDeadline(time.Now().Add(time.Second))
	var denied map[string]string
	if e := bad.ReadJSON(&denied); e != nil || denied["op"] != "rejected" {
		t.Fatal("expected rejection", e)
	}
	if _, _, e := bad.ReadMessage(); e == nil {
		t.Fatal("invalid agent remained connected")
	}
	bad.Close()
	ws := connectTestAgent(t, s, true)
	defer ws.Close()
	if _, e := g.getAgent(targetFor(1)); e != nil {
		t.Fatal(e)
	}
	wrong := targetFor(1)
	wrong.TenantID = "tenant-b"
	if _, e := g.endpoint(wrong); e == nil {
		t.Fatal("cross tenant endpoint accepted")
	}
	for _, ip := range []string{"127.0.0.1", "169.254.169.254", "example.test", "192.168.1.999"} {
		wrong.IP = ip
		if validTarget(wrong) {
			t.Fatal("invalid target accepted", ip)
		}
	}
	r := httptest.NewRequest("POST", "/endpoint", strings.NewReader(`{}`))
	w := httptest.NewRecorder()
	g.internal(w, r)
	if w.Code != 403 {
		t.Fatal("missing control secret accepted")
	}
}
func TestTenDevicesAndLargeResponseFlowControl(t *testing.T) {
	g, s := testGateway(t)
	ws := connectTestAgent(t, s, true)
	defer ws.Close()
	payload := bytes.Repeat([]byte("matrix-data-"), 12000)
	var writeMu sync.Mutex
	send := func(k byte, id uint32, b []byte) {
		writeMu.Lock()
		defer writeMu.Unlock()
		ws.WriteMessage(websocket.BinaryMessage, frame(k, id, b))
	}
	// Emulate an ESP that grants credit and sends a body larger than its 32 KiB window.
	go func() {
		requests := map[uint32][]byte{}
		offset := map[uint32]int{}
		credit := map[uint32]int{}
		responses := map[uint32][]byte{}
		flush := func(id uint32) {
			for offset[id] < len(responses[id]) && credit[id] > 0 {
				n := chunkSize
				if n > credit[id] {
					n = credit[id]
				}
				if n > len(responses[id])-offset[id] {
					n = len(responses[id]) - offset[id]
				}
				send(dataFrame, id, responses[id][offset[id]:offset[id]+n])
				offset[id] += n
				credit[id] -= n
			}
			if offset[id] == len(responses[id]) {
				send(halfFrame, id, nil)
				delete(responses, id)
			}
		}
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
					writeMu.Lock()
					ws.WriteJSON(map[string]interface{}{"op": "opened", "stream": v.Stream})
					writeMu.Unlock()
					credit[v.Stream] = windowSize
				}
				continue
			}
			id := binary.BigEndian.Uint32(p[1:5])
			switch p[0] {
			case dataFrame:
				requests[id] = append(requests[id], p[5:]...)
				b := make([]byte, 4)
				binary.BigEndian.PutUint32(b, uint32(len(p)-5))
				send(creditFrame, id, b)
				if bytes.Contains(requests[id], []byte("\r\n\r\n")) {
					responses[id] = append([]byte(fmt.Sprintf("HTTP/1.1 200 OK\r\nContent-Length: %d\r\nConnection: close\r\n\r\n", len(payload))), payload...)
					delete(requests, id)
					flush(id)
				}
			case creditFrame:
				credit[id] += int(binary.BigEndian.Uint32(p[5:]))
				if _, ok := responses[id]; ok {
					flush(id)
				}
			case closeFrame:
				delete(responses, id)
			}
		}
	}()
	client := &http.Client{Timeout: 5 * time.Second}
	for i := 0; i < 10; i++ {
		l, e := g.endpoint(targetFor(i))
		if e != nil {
			t.Fatal(e)
		}
		r, e := client.Get("http://" + l.Addr().String() + "/device.cgi/test")
		if e != nil {
			t.Fatal(i, e)
		}
		b, e := io.ReadAll(r.Body)
		r.Body.Close()
		if e != nil || !bytes.Equal(b, payload) {
			t.Fatalf("device %d: body=%d error=%v", i, len(b), e)
		}
	}
	var wg sync.WaitGroup
	failures := make(chan error, 8)
	for i := 0; i < 8; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			l, e := g.endpoint(targetFor(i))
			if e != nil {
				failures <- e
				return
			}
			r, e := client.Get("http://" + l.Addr().String() + "/concurrent")
			if e != nil {
				failures <- e
				return
			}
			b, e := io.ReadAll(r.Body)
			r.Body.Close()
			if e != nil || !bytes.Equal(b, payload) {
				failures <- fmt.Errorf("concurrent device %d length=%d: %v", i, len(b), e)
			}
		}(i)
	}
	wg.Wait()
	close(failures)
	for e := range failures {
		t.Error(e)
	}
	if len(g.endpoints) != 10 {
		t.Fatal("expected ten registered endpoints")
	}
}
func TestWebSessionHostBindingExpiryReplayAndCookie(t *testing.T) {
	g, s := testGateway(t)
	ws := connectTestAgent(t, s, true)
	defer ws.Close()
	v := targetFor(1)
	create := func() string {
		body, _ := json.Marshal(v)
		r := httptest.NewRequest("POST", "/web-session", bytes.NewReader(body))
		r.Header.Set("X-Bridge-Gateway-Key", g.key)
		w := httptest.NewRecorder()
		g.internal(w, r)
		if w.Code != 200 {
			t.Fatal(w.Code, w.Body.String())
		}
		var out map[string]string
		json.Unmarshal(w.Body.Bytes(), &out)
		return out["token"]
	}
	token := create()
	launch := func(host, token string) *httptest.ResponseRecorder {
		r := httptest.NewRequest("GET", "https://"+host+"/launch/"+token, nil)
		w := httptest.NewRecorder()
		g.web(w, r)
		return w
	}
	if w := launch("matrix-other.example.test", token); w.Code != 401 {
		t.Fatal("wrong host accepted")
	}
	w := launch(v.WebHost, token)
	if w.Code != 303 {
		t.Fatal(w.Code)
	}
	cookies := w.Result().Cookies()
	if len(cookies) != 1 || !cookies[0].Secure || !cookies[0].HttpOnly || cookies[0].SameSite != http.SameSiteStrictMode {
		t.Fatal("unsafe session cookie")
	}
	if launch(v.WebHost, token).Code != 401 {
		t.Fatal("launch token replay accepted")
	}
	expired := create()
	g.sessions[expired].Expires = time.Now().Add(-time.Minute)
	if launch(v.WebHost, expired).Code != 401 {
		t.Fatal("expired launch accepted")
	}
	r := httptest.NewRequest("POST", "/revoke", strings.NewReader(`{"bridge_id":"bridge-a"}`))
	r.Header.Set("X-Bridge-Gateway-Key", g.key)
	revoke := httptest.NewRecorder()
	g.internal(revoke, r)
	ws.SetReadDeadline(time.Now().Add(time.Second))
	if _, _, e := ws.ReadMessage(); e == nil {
		t.Fatal("revocation did not close agent")
	}
}

func TestBrowserProxyPreservesMatrixLoginCookies(t *testing.T) {
	g, server := testGateway(t)
	ws := connectTestAgent(t, server, true)
	defer ws.Close()
	token := randomToken()
	v := targetFor(1)
	v.WebHost = "bridge.example.test"
	g.sessions[token] = &webSession{Target: v, Used: true, Expires: time.Now().Add(time.Minute)}
	captured := make(chan string, 1)
	go func() {
		var request []byte
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
			id := binary.BigEndian.Uint32(p[1:5])
			if p[0] == dataFrame {
				request = append(request, p[5:]...)
				b := make([]byte, 4)
				binary.BigEndian.PutUint32(b, uint32(len(p)-5))
				ws.WriteMessage(websocket.BinaryMessage, frame(creditFrame, id, b))
				if bytes.Contains(request, []byte("\r\n\r\n")) {
					captured <- string(request)
					ws.WriteMessage(websocket.BinaryMessage, frame(dataFrame, id, []byte("HTTP/1.1 200 OK\r\nContent-Length: 2\r\nSet-Cookie: MatrixSID=next; Path=/\r\nConnection: close\r\n\r\nOK")))
					ws.WriteMessage(websocket.BinaryMessage, frame(halfFrame, id, nil))
					return
				}
			}
		}
	}()
	req, _ := http.NewRequest("GET", server.URL+"/main.html", nil)
	req.Host = "bridge.example.test"
	req.AddCookie(&http.Cookie{Name: "__Host-biomatrix-bridge", Value: token})
	req.AddCookie(&http.Cookie{Name: "MatrixSID", Value: "current"})
	req.Header.Set("Authorization", `Digest username="matrix-user"`)
	resp, e := (&http.Client{Timeout: 3 * time.Second}).Do(req)
	if e != nil {
		t.Fatal(e)
	}
	defer resp.Body.Close()
	body, e := io.ReadAll(resp.Body)
	if e != nil || string(body) != "OK" {
		t.Fatal("browser response", e)
	}
	request := <-captured
	if !strings.Contains(request, "MatrixSID=current") || !strings.Contains(request, `Digest username="matrix-user"`) || strings.Contains(request, "__Host-biomatrix-bridge") {
		t.Fatal("Matrix credentials/cookies were not forwarded correctly")
	}
	if cookies := resp.Cookies(); len(cookies) != 1 || cookies[0].Name != "MatrixSID" {
		t.Fatal("device response cookie lost")
	}
}

func TestPendingApprovalAgentCannotForward(t *testing.T) {
	g, server := testGateway(t)
	ws, _, e := websocket.DefaultDialer.Dial("ws"+strings.TrimPrefix(server.URL, "http")+"/agent", nil)
	if e != nil {
		t.Fatal(e)
	}
	defer ws.Close()
	var challenge map[string]string
	ws.ReadJSON(&challenge)
	ws.WriteJSON(map[string]string{"op": "auth", "bridge_id": "pending-bridge", "signature": "test-proof:" + challenge["nonce"]})
	var pending map[string]string
	if e = ws.ReadJSON(&pending); e != nil || pending["op"] != "pending" {
		t.Fatal("pending result", e)
	}
	v := targetFor(1)
	v.BridgeID = "pending-bridge"
	if _, e = g.endpoint(v); e == nil {
		t.Fatal("pending agent got a forwarding endpoint")
	}
}

func TestSharedHostLaunchPreventsSilentDeviceSwitch(t *testing.T) {
	g, server := testGateway(t)
	ws := connectTestAgent(t, server, true)
	defer ws.Close()
	issue := func(i int) string {
		v := targetFor(i)
		v.WebHost = "bridge.example.test"
		body, _ := json.Marshal(v)
		req := httptest.NewRequest("POST", "/web-session", bytes.NewReader(body))
		req.Header.Set("X-Bridge-Gateway-Key", g.key)
		w := httptest.NewRecorder()
		g.internal(w, req)
		if w.Code != 200 {
			t.Fatal(w.Code)
		}
		var result map[string]string
		json.Unmarshal(w.Body.Bytes(), &result)
		return result["token"]
	}
	launch := func(token, cookie string) *httptest.ResponseRecorder {
		req := httptest.NewRequest("GET", "https://bridge.example.test/launch/"+token, nil)
		if cookie != "" {
			req.AddCookie(&http.Cookie{Name: "__Host-biomatrix-bridge", Value: cookie})
		}
		w := httptest.NewRecorder()
		g.web(w, req)
		return w
	}
	first := issue(1)
	if launch(first, "").Code != 303 {
		t.Fatal("shared launch failed")
	}
	other := issue(2)
	if launch(other, first).Code != 409 {
		t.Fatal("active browser silently switched devices")
	}
	if g.sessions[other].Used {
		t.Fatal("blocked token was consumed")
	}
	if launch(other, "").Code != 303 {
		t.Fatal("separate browser could not open second device")
	}
	renewal := issue(1)
	if launch(renewal, first).Code != 303 {
		t.Fatal("same device renewal failed")
	}
}

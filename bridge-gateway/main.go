package main

import (
	"bytes"
	"crypto/rand"
	"crypto/subtle"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"log"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"os"
	"strings"
	"sync"
	"time"

	"github.com/gorilla/websocket"
)

const (
	chunkSize   = 4096
	windowSize  = 32768
	maxStreams  = 8
	dataFrame   = byte(1)
	closeFrame  = byte(2)
	halfFrame   = byte(3)
	creditFrame = byte(4)
)

type target struct {
	BridgeID string `json:"bridge_id"`
	TenantID string `json:"tenant_id"`
	DeviceID string `json:"device_id"`
	IP       string `json:"ip"`
	Port     int    `json:"port"`
	WebHost  string `json:"web_host,omitempty"`
}
type stream struct {
	id                  uint32
	conn                net.Conn
	inbox               chan []byte
	credit              chan int
	opened              chan error
	done                chan struct{}
	once                sync.Once
	agent               *agent
	eofMu               sync.Mutex
	localEOF, remoteEOF bool
}
type agent struct {
	id, tenant  string
	ws          *websocket.Conn
	writeMu, mu sync.Mutex
	streams     map[uint32]*stream
	next        uint32
	done        chan struct{}
	once        sync.Once
}
type webSession struct {
	Target  target
	Expires time.Time
	Used    bool
}
type gateway struct {
	key, app, domain string
	mu               sync.Mutex
	agents           map[string]*agent
	endpoints        map[string]net.Listener
	sessions         map[string]*webSession
	httpClient       *http.Client
	handshakes       chan struct{}
}

func randomToken() string {
	b := make([]byte, 32)
	if _, e := rand.Read(b); e != nil {
		panic(e)
	}
	return hex.EncodeToString(b)
}
func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(status)
	json.NewEncoder(w).Encode(v)
}
func (g *gateway) appCall(path string, input any, output any) (int, error) {
	body, _ := json.Marshal(input)
	req, e := http.NewRequest("POST", g.app+"/api/bridge-internal/"+path, bytes.NewReader(body))
	if e != nil {
		return 0, e
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("X-Bridge-Gateway-Key", g.key)
	r, e := g.httpClient.Do(req)
	if e != nil {
		return 0, e
	}
	defer r.Body.Close()
	if r.StatusCode != 200 {
		io.Copy(io.Discard, io.LimitReader(r.Body, 4096))
		return r.StatusCode, errors.New("registry rejected request")
	}
	if output != nil {
		e = json.NewDecoder(io.LimitReader(r.Body, 16384)).Decode(output)
	}
	return r.StatusCode, e
}
func (a *agent) text(v any) error {
	a.writeMu.Lock()
	defer a.writeMu.Unlock()
	a.ws.SetWriteDeadline(time.Now().Add(5 * time.Second))
	return a.ws.WriteJSON(v)
}
func (a *agent) binary(kind byte, id uint32, payload []byte) error {
	if len(payload) > chunkSize {
		return errors.New("frame too large")
	}
	b := make([]byte, 5+len(payload))
	b[0] = kind
	binary.BigEndian.PutUint32(b[1:5], id)
	copy(b[5:], payload)
	a.writeMu.Lock()
	defer a.writeMu.Unlock()
	a.ws.SetWriteDeadline(time.Now().Add(5 * time.Second))
	return a.ws.WriteMessage(websocket.BinaryMessage, b)
}
func (s *stream) close(notify bool) {
	s.once.Do(func() {
		close(s.done)
		s.conn.Close()
		s.agent.mu.Lock()
		delete(s.agent.streams, s.id)
		s.agent.mu.Unlock()
		if notify {
			s.agent.binary(closeFrame, s.id, nil)
		}
	})
}
func (a *agent) close() {
	a.once.Do(func() {
		close(a.done)
		a.ws.Close()
		a.mu.Lock()
		all := make([]*stream, 0, len(a.streams))
		for _, s := range a.streams {
			all = append(all, s)
		}
		a.mu.Unlock()
		for _, s := range all {
			s.close(false)
		}
	})
}
func (a *agent) attach(c net.Conn, t target) error {
	a.mu.Lock()
	if len(a.streams) >= maxStreams {
		a.mu.Unlock()
		return errors.New("bridge stream limit reached")
	}
	select {
	case <-a.done:
		a.mu.Unlock()
		return errors.New("bridge disconnected")
	default:
	}
	a.next++
	id := a.next
	if id == 0 {
		a.next++
		id = a.next
	}
	s := &stream{id: id, conn: c, inbox: make(chan []byte, 8), credit: make(chan int, 16), opened: make(chan error, 1), done: make(chan struct{}), agent: a}
	a.streams[id] = s
	a.mu.Unlock()
	if e := a.text(map[string]any{"op": "open", "stream": id, "ip": t.IP, "port": t.Port, "device_id": t.DeviceID}); e != nil {
		s.close(false)
		return e
	}
	select {
	case e := <-s.opened:
		if e != nil {
			s.close(false)
			return e
		}
	case <-time.After(5 * time.Second):
		s.close(true)
		return errors.New("device open timed out")
	case <-a.done:
		s.close(false)
		return errors.New("bridge disconnected")
	}
	go s.toDevice()
	go s.fromDevice()
	return nil
}
func (s *stream) finished(local bool) {
	s.eofMu.Lock()
	if local {
		s.localEOF = true
	} else {
		s.remoteEOF = true
	}
	complete := s.localEOF && s.remoteEOF
	s.eofMu.Unlock()
	if complete {
		s.close(true)
	}
}
func (s *stream) toDevice() {
	defer func() { s.agent.binary(halfFrame, s.id, nil); s.finished(true) }()
	credit := windowSize
	buf := make([]byte, chunkSize)
	for {
		for credit == 0 {
			select {
			case n := <-s.credit:
				credit += n
			case <-s.done:
				return
			case <-time.After(60 * time.Second):
				s.close(true)
				return
			}
		}
		select {
		case n := <-s.credit:
			credit += n
		default:
		}
		if credit > windowSize {
			s.close(true)
			return
		}
		limit := chunkSize
		if credit < limit {
			limit = credit
		}
		s.conn.SetReadDeadline(time.Now().Add(60 * time.Second))
		n, e := s.conn.Read(buf[:limit])
		if n > 0 {
			if s.agent.binary(dataFrame, s.id, buf[:n]) != nil {
				s.close(false)
				return
			}
			credit -= n
		}
		if e != nil {
			if e != io.EOF {
				s.close(true)
			}
			return
		}
	}
}
func (s *stream) fromDevice() {
	for {
		select {
		case data := <-s.inbox:
			s.conn.SetWriteDeadline(time.Now().Add(15 * time.Second))
			if data == nil {
				if tcp, ok := s.conn.(*net.TCPConn); ok {
					tcp.CloseWrite()
				}
				s.finished(false)
				continue
			}
			left := data
			for len(left) > 0 {
				n, e := s.conn.Write(left)
				if e != nil {
					s.close(true)
					return
				}
				left = left[n:]
			}
			b := make([]byte, 4)
			binary.BigEndian.PutUint32(b, uint32(len(data)))
			if s.agent.binary(creditFrame, s.id, b) != nil {
				s.close(false)
				return
			}
		case <-s.done:
			return
		}
	}
}
func (g *gateway) serveAgent(w http.ResponseWriter, r *http.Request) {
	if r.Host != "bridge."+g.domain && !strings.HasPrefix(r.Host, "127.0.0.1:") {
		http.Error(w, "Unknown gateway host", 404)
		return
	}
	admitted := false
	if g.handshakes != nil {
		select {
		case g.handshakes <- struct{}{}:
		default:
			http.Error(w, "Gateway busy", 503)
			return
		}
		admitted = true
		defer func() {
			if admitted {
				<-g.handshakes
			}
		}()
	}
	up := websocket.Upgrader{ReadBufferSize: 8192, WriteBufferSize: 8192, CheckOrigin: func(r *http.Request) bool { return r.Header.Get("Origin") == "" }}
	ws, e := up.Upgrade(w, r, nil)
	if e != nil {
		return
	}
	ws.SetReadLimit(8192)
	ws.SetReadDeadline(time.Now().Add(15 * time.Second))
	nonce := randomToken()
	ws.WriteJSON(map[string]string{"op": "challenge", "nonce": nonce})
	var proof struct {
		Op, BridgeID, Signature string
		Bridge                  string `json:"bridge_id"`
	}
	if e = ws.ReadJSON(&proof); e != nil || proof.Op != "auth" {
		ws.Close()
		return
	}
	var identity struct {
		BridgeID string `json:"bridge_id"`
		TenantID string `json:"tenant_id"`
	}
	status, e := g.appCall("auth", map[string]string{"bridge_id": proof.Bridge, "nonce": nonce, "signature": proof.Signature}, &identity)
	if e != nil || identity.BridgeID != proof.Bridge {
		ws.SetWriteDeadline(time.Now().Add(5 * time.Second))
		if status == 409 {
			ws.WriteJSON(map[string]string{"op": "pending"})
		} else if status == 403 {
			ws.WriteJSON(map[string]string{"op": "rejected"})
		}
		ws.Close()
		return
	}
	if admitted {
		<-g.handshakes
		admitted = false
	}
	a := &agent{id: identity.BridgeID, tenant: identity.TenantID, ws: ws, streams: make(map[uint32]*stream), done: make(chan struct{})}
	g.mu.Lock()
	old := g.agents[a.id]
	g.agents[a.id] = a
	g.mu.Unlock()
	if old != nil {
		old.close()
	}
	defer func() {
		a.close()
		g.mu.Lock()
		if g.agents[a.id] == a {
			delete(g.agents, a.id)
			for key, listener := range g.endpoints {
				parts := strings.Split(key, ":")
				if len(parts) == 4 && parts[2] == a.id {
					listener.Close()
					delete(g.endpoints, key)
				}
			}
		}
		g.mu.Unlock()
	}()
	a.text(map[string]any{"op": "ready", "window": windowSize, "max_streams": maxStreams})
	go g.heartbeat(a)
	for {
		ws.SetReadDeadline(time.Now().Add(75 * time.Second))
		kind, b, e := ws.ReadMessage()
		if e != nil {
			return
		}
		if kind == websocket.TextMessage {
			var msg struct {
				Op     string
				Stream uint32
				Error  string
			}
			if json.Unmarshal(b, &msg) != nil {
				return
			}
			if msg.Op == "heartbeat" {
				continue
			}
			a.mu.Lock()
			s := a.streams[msg.Stream]
			a.mu.Unlock()
			if s == nil {
				continue
			}
			if msg.Op == "opened" {
				select {
				case s.opened <- nil:
				default:
				}
			} else if msg.Op == "error" {
				select {
				case s.opened <- errors.New("local device connection failed"):
				default:
				}
				s.close(false)
			}
			continue
		}
		if kind != websocket.BinaryMessage || len(b) < 5 || len(b) > chunkSize+5 {
			return
		}
		id := binary.BigEndian.Uint32(b[1:5])
		a.mu.Lock()
		s := a.streams[id]
		a.mu.Unlock()
		if s == nil {
			continue
		}
		switch b[0] {
		case dataFrame:
			payload := append([]byte(nil), b[5:]...)
			select {
			case s.inbox <- payload:
			default:
				s.close(true)
			}
		case creditFrame:
			if len(b) != 9 {
				return
			}
			n := int(binary.BigEndian.Uint32(b[5:]))
			if n < 1 || n > windowSize {
				return
			}
			select {
			case s.credit <- n:
			default:
				s.close(true)
			}
		case closeFrame:
			s.close(false)
		case halfFrame:
			select {
			case s.inbox <- nil:
			default:
				s.close(true)
			}
		default:
			return
		}
	}
}
func (g *gateway) heartbeat(a *agent) {
	tick := time.NewTicker(30 * time.Second)
	defer tick.Stop()
	failures := 0
	for {
		select {
		case <-a.done:
			return
		case <-tick.C:
			status, e := g.appCall("heartbeat", map[string]string{"bridge_id": a.id}, nil)
			if e != nil {
				failures++
				if status == 403 || failures >= 3 {
					a.close()
					return
				}
			} else {
				failures = 0
			}
			if a.text(map[string]string{"op": "heartbeat"}) != nil {
				a.close()
				return
			}
		}
	}
}
func validTarget(t target) bool {
	ip := net.ParseIP(t.IP)
	return ip != nil && ip.To4() != nil && strings.HasPrefix(t.IP, "192.168.") && t.Port == 80 && t.BridgeID != "" && t.TenantID != "" && t.DeviceID != ""
}
func (g *gateway) getAgent(t target) (*agent, error) {
	g.mu.Lock()
	a := g.agents[t.BridgeID]
	g.mu.Unlock()
	if a == nil || a.tenant != t.TenantID {
		return nil, errors.New("bridge offline or tenant mismatch")
	}
	return a, nil
}
func (g *gateway) endpoint(t target) (net.Listener, error) {
	key := t.TenantID + ":" + t.DeviceID + ":" + t.BridgeID + ":" + t.IP
	if _, e := g.getAgent(t); e != nil {
		return nil, e
	}
	g.mu.Lock()
	defer g.mu.Unlock()
	if l := g.endpoints[key]; l != nil {
		return l, nil
	}
	if len(g.endpoints) >= 1024 {
		return nil, errors.New("endpoint limit reached")
	}
	l, e := net.Listen("tcp4", "127.0.0.1:0")
	if e != nil {
		return nil, e
	}
	g.endpoints[key] = l
	go func() {
		for {
			c, e := l.Accept()
			if e != nil {
				return
			}
			a, e := g.getAgent(t)
			if e != nil {
				c.Close()
				continue
			}
			if e = a.attach(c, t); e != nil {
				c.Close()
			}
		}
	}()
	return l, nil
}
func (g *gateway) internal(w http.ResponseWriter, r *http.Request) {
	if r.Method != "POST" || subtle.ConstantTimeCompare([]byte(r.Header.Get("X-Bridge-Gateway-Key")), []byte(g.key)) != 1 {
		http.Error(w, "Forbidden", 403)
		return
	}
	r.Body = http.MaxBytesReader(w, r.Body, 4096)
	var t target
	if json.NewDecoder(r.Body).Decode(&t) != nil {
		http.Error(w, "Invalid request", 400)
		return
	}
	if r.URL.Path == "/revoke" {
		g.mu.Lock()
		a := g.agents[t.BridgeID]
		g.mu.Unlock()
		if a != nil {
			a.close()
		}
		writeJSON(w, 200, map[string]bool{"ok": true})
		return
	}
	if !validTarget(t) {
		http.Error(w, "Invalid target", 400)
		return
	}
	switch r.URL.Path {
	case "/endpoint":
		l, e := g.endpoint(t)
		if e != nil {
			http.Error(w, "Bridge unavailable", 503)
			return
		}
		writeJSON(w, 200, map[string]int{"port": l.Addr().(*net.TCPAddr).Port})
	case "/web-session":
		if !g.validWebHost(t.WebHost) {
			http.Error(w, "Invalid web host", 400)
			return
		}
		if _, e := g.getAgent(t); e != nil {
			http.Error(w, "Bridge unavailable", 503)
			return
		}
		token := randomToken()
		g.mu.Lock()
		for key, session := range g.sessions {
			if time.Now().After(session.Expires) {
				delete(g.sessions, key)
			}
		}
		if len(g.sessions) >= 1024 {
			g.mu.Unlock()
			http.Error(w, "Session limit", 503)
			return
		}
		g.sessions[token] = &webSession{Target: t, Expires: time.Now().Add(time.Minute)}
		g.mu.Unlock()
		writeJSON(w, 200, map[string]string{"token": token})
	default:
		http.NotFound(w, r)
	}
}
func (g *gateway) validWebHost(host string) bool {
	return host == "bridge."+g.domain || (strings.HasPrefix(host, "matrix-") && strings.HasSuffix(host, "."+g.domain))
}
func (g *gateway) web(w http.ResponseWriter, r *http.Request) {
	host := strings.Split(r.Host, ":")[0]
	if !g.validWebHost(host) {
		http.NotFound(w, r)
		return
	}
	g.mu.Lock()
	for token, s := range g.sessions {
		if time.Now().After(s.Expires) {
			delete(g.sessions, token)
		}
	}
	var session *webSession
	var key string
	if strings.HasPrefix(r.URL.Path, "/launch/") {
		key = strings.TrimPrefix(r.URL.Path, "/launch/")
		s := g.sessions[key]
		if s != nil && !s.Used && s.Target.WebHost == host && !time.Now().After(s.Expires) {
			if cookie, err := r.Cookie("__Host-biomatrix-bridge"); err == nil {
				old := g.sessions[cookie.Value]
				if old != nil && old.Used && old.Target.WebHost == host && time.Now().Before(old.Expires) {
					if old.Target.BridgeID != s.Target.BridgeID || old.Target.TenantID != s.Target.TenantID || old.Target.DeviceID != s.Target.DeviceID || old.Target.IP != s.Target.IP {
						g.mu.Unlock()
						w.Header().Set("Cache-Control", "no-store")
						http.Error(w, "Another Matrix device is active in this browser. Use a separate private browser window for this device. Close old Matrix tabs before opening a different device after the session expires.", 409)
						return
					}
					delete(g.sessions, cookie.Value)
				}
			}
			s.Used = true
			s.Expires = time.Now().Add(15 * time.Minute)
			session = s
		}
	} else if cookie, e := r.Cookie("__Host-biomatrix-bridge"); e == nil {
		key = cookie.Value
		s := g.sessions[key]
		if s != nil && s.Used && s.Target.WebHost == host && !time.Now().After(s.Expires) {
			session = s
		}
	}
	g.mu.Unlock()
	if session == nil {
		http.Error(w, "Open this device from your biomatrix account to start an authorized session.", 401)
		return
	}
	if strings.HasPrefix(r.URL.Path, "/launch/") {
		http.SetCookie(w, &http.Cookie{Name: "__Host-biomatrix-bridge", Value: key, Path: "/", Secure: true, HttpOnly: true, SameSite: http.SameSiteStrictMode, MaxAge: 900})
		w.Header().Set("Referrer-Policy", "no-referrer")
		http.Redirect(w, r, "/", 303)
		return
	}
	l, e := g.endpoint(session.Target)
	if e != nil {
		http.Error(w, "Bridge disconnected", 503)
		return
	}
	origin, _ := url.Parse("http://" + l.Addr().String())
	proxy := httputil.NewSingleHostReverseProxy(origin)
	proxy.Transport = &http.Transport{DisableKeepAlives: true, ResponseHeaderTimeout: 20 * time.Second}
	proxy.ErrorHandler = func(w http.ResponseWriter, r *http.Request, e error) {
		http.Error(w, "Device unavailable; operation outcome may be unknown.", 502)
	}
	r.Header.Del("X-Bridge-Gateway-Key")
	cookies := r.Cookies()
	r.Header.Del("Cookie")
	if len(cookies) > 0 {
		for _, c := range cookies {
			if c.Name != "__Host-biomatrix-bridge" {
				r.AddCookie(c)
			}
		}
	}
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("Referrer-Policy", "no-referrer")
	proxy.ServeHTTP(w, r)
}
func env(name, fallback string) string {
	if v := os.Getenv(name); v != "" {
		return v
	}
	return fallback
}
func main() {
	key := os.Getenv("BRIDGE_GATEWAY_KEY")
	if len(key) < 32 {
		log.Fatal("BRIDGE_GATEWAY_KEY must contain at least 32 characters")
	}
	app := env("BRIDGE_APP_URL", "http://127.0.0.1:8000")
	u, e := url.Parse(app)
	if e != nil || u.Hostname() != "127.0.0.1" {
		log.Fatal("BRIDGE_APP_URL must be loopback")
	}
	g := &gateway{key: key, app: strings.TrimRight(app, "/"), domain: env("BRIDGE_WEB_DOMAIN", "aflahdev.in"), agents: map[string]*agent{}, endpoints: map[string]net.Listener{}, sessions: map[string]*webSession{}, httpClient: &http.Client{Timeout: 5 * time.Second}, handshakes: make(chan struct{}, 64)}
	mux := http.NewServeMux()
	mux.HandleFunc("/agent", g.serveAgent)
	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) { writeJSON(w, 200, map[string]string{"status": "ready"}) })
	mux.HandleFunc("/", g.web)
	controlAddr := env("BRIDGE_CONTROL_ADDR", "127.0.0.1:8072")
	publicAddr := env("BRIDGE_LISTEN_ADDR", "127.0.0.1:8071")
	for _, addr := range []string{controlAddr, publicAddr} {
		host, _, err := net.SplitHostPort(addr)
		if err != nil || host != "127.0.0.1" {
			log.Fatal("Gateway listeners must use 127.0.0.1")
		}
	}
	internal := &http.Server{Addr: controlAddr, Handler: http.HandlerFunc(g.internal), ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 10 * time.Second, WriteTimeout: 10 * time.Second, MaxHeaderBytes: 8192}
	go func() { log.Fatal(internal.ListenAndServe()) }()
	public := &http.Server{Addr: publicAddr, Handler: mux, ReadHeaderTimeout: 5 * time.Second, IdleTimeout: 60 * time.Second, MaxHeaderBytes: 16384}
	log.Print("Bridge gateway starting; private control on loopback")
	log.Fatal(public.ListenAndServe())
}

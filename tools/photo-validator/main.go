package main

import (
	"bytes"
	_ "embed"
	"encoding/json"
	"fmt"
	"image"
	"image/color"
	"image/jpeg"
	"io"
	"math"
	"os"
	"sort"

	pigo "github.com/esimov/pigo/core"
)

//go:embed models/facefinder
var faceModel []byte

//go:embed models/puploc
var eyeModel []byte

const maxBytes = 786432

type result struct {
	OK      bool   `json:"ok"`
	Code    string `json:"code"`
	Faces   int    `json:"faces"`
	Version string `json:"version"`
}

type validator struct {
	faces *pigo.Pigo
	eyes  *pigo.PuplocCascade
}

func newValidator() (*validator, error) {
	faces, err := pigo.NewPigo().Unpack(faceModel)
	if err != nil {
		return nil, err
	}
	eyes, err := pigo.NewPuplocCascade().UnpackCascade(eyeModel)
	return &validator{faces: faces, eyes: eyes}, err
}

func outcome(code string, faces int) result {
	return result{OK: code == "accepted", Code: code, Faces: faces, Version: "pigo-1.4.6-v1"}
}

func (v *validator) validate(data []byte) result {
	if len(data) == 0 || len(data) > maxBytes {
		return outcome("invalid_photo", 0)
	}
	config, err := jpeg.DecodeConfig(bytes.NewReader(data))
	if err != nil || config.Width < 160 || config.Height < 120 || config.Width > 1600 || config.Height > 1600 {
		return outcome("invalid_photo", 0)
	}
	img, err := jpeg.Decode(bytes.NewReader(data))
	if err != nil {
		return outcome("invalid_photo", 0)
	}
	gray := resizeGray(img, 640)
	width, height := gray.Bounds().Dx(), gray.Bounds().Dy()
	params := pigo.ImageParams{Pixels: gray.Pix, Rows: height, Cols: width, Dim: gray.Stride}
	detections := v.faces.RunCascade(pigo.CascadeParams{MinSize: 40, MaxSize: min(width, height), ShiftFactor: 0.1, ScaleFactor: 1.1, ImageParams: params}, 0)
	detections = v.faces.ClusterDetections(detections, 0.2)
	faces := distinctFaces(detections)
	if len(faces) == 0 {
		return outcome("no_face", 0)
	}
	if len(faces) != 1 {
		return outcome("multiple_faces", len(faces))
	}
	face := faces[0]
	if face.Q < 10 {
		return outcome("face_not_clear", 1)
	}
	if code := quality(gray, face); code != "accepted" {
		return outcome(code, 1)
	}
	left := v.eyes.RunDetector(pigo.Puploc{Row: face.Row - int(0.075*float32(face.Scale)), Col: face.Col - int(0.175*float32(face.Scale)), Scale: float32(face.Scale) * 0.25, Perturbs: 63}, params, 0, false)
	right := v.eyes.RunDetector(pigo.Puploc{Row: face.Row - int(0.075*float32(face.Scale)), Col: face.Col + int(0.185*float32(face.Scale)), Scale: float32(face.Scale) * 0.25, Perturbs: 63}, params, 0, false)
	scale := float64(face.Scale)
	if left == nil || right == nil || left.Col <= 0 || right.Col <= 0 || float64(right.Col-left.Col) < scale*0.2 || float64(right.Col-left.Col) > scale*0.6 || math.Abs(float64(right.Row-left.Row)) > scale*0.15 || left.Row >= face.Row || right.Row >= face.Row {
		return outcome("face_not_clear", 1)
	}
	return outcome("accepted", 1)
}

// Suppress overlapping scale clusters before counting distinct people.
func distinctFaces(detections []pigo.Detection) []pigo.Detection {
	sort.Slice(detections, func(i, j int) bool { return detections[i].Q > detections[j].Q })
	faces := []pigo.Detection{}
	for _, candidate := range detections {
		if candidate.Q < 5 {
			continue
		}
		duplicate := false
		for _, face := range faces {
			a, b := float64(candidate.Scale), float64(face.Scale)
			dx := math.Max(0, math.Min(float64(candidate.Col)+a/2, float64(face.Col)+b/2)-math.Max(float64(candidate.Col)-a/2, float64(face.Col)-b/2))
			dy := math.Max(0, math.Min(float64(candidate.Row)+a/2, float64(face.Row)+b/2)-math.Max(float64(candidate.Row)-a/2, float64(face.Row)-b/2))
			intersection := dx * dy
			if intersection/(a*a+b*b-intersection) > 0.35 {
				duplicate = true
				break
			}
		}
		if !duplicate {
			faces = append(faces, candidate)
		}
	}
	return faces
}

func resizeGray(img image.Image, maxSide int) *image.Gray {
	bounds := img.Bounds()
	width, height := bounds.Dx(), bounds.Dy()
	scale := math.Min(1, float64(maxSide)/float64(max(width, height)))
	out := image.NewGray(image.Rect(0, 0, max(1, int(float64(width)*scale)), max(1, int(float64(height)*scale))))
	for y := 0; y < out.Bounds().Dy(); y++ {
		for x := 0; x < out.Bounds().Dx(); x++ {
			// Bilinear sampling avoids introducing hard pixel edges into the blur measurement.
			sx := math.Min(float64(width-1), float64(x)/scale)
			sy := math.Min(float64(height-1), float64(y)/scale)
			x0, y0 := int(sx), int(sy)
			x1, y1 := min(x0+1, width-1), min(y0+1, height-1)
			dx, dy := sx-float64(x0), sy-float64(y0)
			at := func(x, y int) float64 {
				return float64(color.GrayModel.Convert(img.At(bounds.Min.X+x, bounds.Min.Y+y)).(color.Gray).Y)
			}
			value := (1-dy)*((1-dx)*at(x0, y0)+dx*at(x1, y0)) + dy*((1-dx)*at(x0, y1)+dx*at(x1, y1))
			out.SetGray(x, y, color.Gray{Y: uint8(value)})
		}
	}
	return out
}

func quality(gray *image.Gray, face pigo.Detection) string {
	bounds := gray.Bounds()
	if face.Scale < max(80, min(bounds.Dx(), bounds.Dy())/5) {
		return "face_too_small"
	}
	rect := image.Rect(face.Col-face.Scale/2, face.Row-face.Scale/2, face.Col+face.Scale/2, face.Row+face.Scale/2)
	if rect.Min.X < 2 || rect.Min.Y < 2 || rect.Max.X > bounds.Max.X-2 || rect.Max.Y > bounds.Max.Y-2 {
		return "face_cut_off"
	}
	var sum, squares, lapSum, lapSquares, count, clipped float64
	// Measure inside the face, excluding hair/background at the edge of the detector box.
	inset := face.Scale / 6
	rect = rect.Inset(inset)
	at := func(x, y int) float64 { return float64(gray.GrayAt(x, y).Y) }
	for y := rect.Min.Y + 1; y < rect.Max.Y-1; y++ {
		for x := rect.Min.X + 1; x < rect.Max.X-1; x++ {
			pixel := at(x, y)
			sum += pixel
			squares += pixel * pixel
			count++
			if pixel < 15 || pixel > 245 {
				clipped++
			}
			lap := 4*pixel - at(x-1, y) - at(x+1, y) - at(x, y-1) - at(x, y+1)
			lapSum += lap
			lapSquares += lap * lap
		}
	}
	mean := sum / count
	deviation := math.Sqrt(math.Max(0, squares/count-mean*mean))
	if mean < 35 || mean > 220 || clipped/count > 0.45 || deviation < 14 {
		return "poor_lighting"
	}
	if lapSquares/count-math.Pow(lapSum/count, 2) < 35 {
		return "blurry"
	}
	return "accepted"
}

func main() {
	// Images and face coordinates stay in this process; only a small verdict is returned.
	defer func() {
		if recover() != nil {
			fmt.Fprintln(os.Stdout, `{"ok":false,"code":"unavailable","faces":0,"version":"pigo-1.4.6-v1"}`)
			os.Exit(1)
		}
	}()
	data, err := io.ReadAll(io.LimitReader(os.Stdin, maxBytes+1))
	if err != nil {
		os.Exit(1)
	}
	v, err := newValidator()
	if err != nil {
		os.Exit(1)
	}
	if err = json.NewEncoder(os.Stdout).Encode(v.validate(data)); err != nil {
		os.Exit(1)
	}
}

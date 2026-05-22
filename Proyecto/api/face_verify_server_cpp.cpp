#include <opencv2/core.hpp>
#include <opencv2/features2d.hpp>
#include <opencv2/imgcodecs.hpp>
#include <opencv2/imgproc.hpp>
#include <opencv2/objdetect.hpp>

#include <algorithm>
#include <cstdlib>
#include <iomanip>
#include <iostream>
#include <string>
#include <vector>

#include <httplib.h>

using std::string;

namespace {

constexpr double kDefaultThreshold = 0.58;

double clamp01(double value) {
    if (value < 0.0) return 0.0;
    if (value > 1.0) return 1.0;
    return value;
}

double histogram_score(const cv::Mat& a, const cv::Mat& b) {
    int histSize = 64;
    float range[] = {0.0f, 256.0f};
    const float* histRange = {range};
    cv::Mat histA, histB;
    cv::calcHist(&a, 1, nullptr, cv::Mat(), histA, 1, &histSize, &histRange);
    cv::calcHist(&b, 1, nullptr, cv::Mat(), histB, 1, &histSize, &histRange);
    cv::normalize(histA, histA, 1.0, 0.0, cv::NORM_L1);
    cv::normalize(histB, histB, 1.0, 0.0, cv::NORM_L1);
    double corr = cv::compareHist(histA, histB, cv::HISTCMP_CORREL);
    return clamp01((corr + 1.0) / 2.0);
}

bool orb_score(const cv::Mat& a, const cv::Mat& b, double& outScore) {
    auto orb = cv::ORB::create(500);
    std::vector<cv::KeyPoint> kpa, kpb;
    cv::Mat desa, desb;
    orb->detectAndCompute(a, cv::noArray(), kpa, desa);
    orb->detectAndCompute(b, cv::noArray(), kpb, desb);
    if (desa.empty() || desb.empty() || kpa.size() < 8 || kpb.size() < 8) {
        return false;
    }

    cv::BFMatcher matcher(cv::NORM_HAMMING, true);
    std::vector<cv::DMatch> matches;
    matcher.match(desa, desb, matches);
    if (matches.empty()) {
        return false;
    }

    size_t good = 0;
    for (const auto& m : matches) {
        if (m.distance < 64.0f) {
            ++good;
        }
    }
    size_t maxKp = std::max(kpa.size(), kpb.size());
    double raw = maxKp == 0 ? 0.0 : static_cast<double>(good) / static_cast<double>(maxKp);
    outScore = clamp01(raw * 3.0);
    return true;
}

bool extract_face(const cv::Mat& gray, cv::Mat& outFace) {
    static cv::CascadeClassifier cascade;
    static bool loaded = false;
    if (!loaded) {
        const string cascadePath = "/usr/share/opencv4/haarcascades/haarcascade_frontalface_default.xml";
        if (!cascade.load(cascadePath)) {
            if (!cascade.load("/usr/share/opencv/haarcascades/haarcascade_frontalface_default.xml")) {
                return false;
            }
        }
        loaded = true;
    }

    std::vector<cv::Rect> faces;
    cascade.detectMultiScale(gray, faces, 1.1, 5, 0, cv::Size(70, 70));
    if (faces.empty()) {
        return false;
    }
    auto face = *std::max_element(
        faces.begin(),
        faces.end(),
        [](const cv::Rect& lhs, const cv::Rect& rhs) { return lhs.area() < rhs.area(); }
    );

    cv::Mat roi = gray(face).clone();
    cv::equalizeHist(roi, roi);
    cv::resize(roi, outFace, cv::Size(160, 160), 0, 0, cv::INTER_AREA);
    return true;
}

bool read_image_from_upload(const httplib::FormData& file, cv::Mat& out) {
    if (file.content.empty()) return false;
    std::vector<unsigned char> bytes(file.content.begin(), file.content.end());
    out = cv::imdecode(bytes, cv::IMREAD_COLOR);
    return !out.empty();
}

string json_error(const string& reason) {
    return "{\"ok\":false,\"reason\":\"" + reason + "\"}";
}

string json_ok(bool match, double score, double threshold) {
    double distance = 1.0 - score;
    std::ostringstream oss;
    oss << std::fixed << std::setprecision(6)
        << "{\"ok\":true,\"match\":" << (match ? "true" : "false")
        << ",\"score\":" << score
        << ",\"distance\":" << distance
        << ",\"threshold\":" << threshold
        << ",\"engine\":\"opencv_cpp_remote_haar_orb_hist\"}";
    return oss.str();
}

}  // namespace

int main() {
    const char* tokenEnv = std::getenv("FACE_VERIFY_TOKEN");
    const string token = tokenEnv ? string(tokenEnv) : string();
    int port = 5050;
    if (const char* portEnv = std::getenv("FACE_VERIFY_PORT")) {
        try {
            port = std::stoi(portEnv);
        } catch (...) {
            port = 5050;
        }
    }

    httplib::Server svr;

    svr.Get("/health", [](const httplib::Request&, httplib::Response& res) {
        res.set_content("{\"ok\":true,\"engine\":\"opencv_cpp_remote_haar_orb_hist\"}", "application/json");
    });

    svr.Post("/verify", [&](const httplib::Request& req, httplib::Response& res) {
        if (!token.empty()) {
            string provided = req.has_param("token") ? req.get_param_value("token") : "";
            if (provided != token) {
                res.status = 401;
                res.set_content(json_error("invalid_token"), "application/json");
                return;
            }
        }

        if (!req.form.has_file("reference") || !req.form.has_file("captured")) {
            res.status = 400;
            res.set_content(json_error("missing_files"), "application/json");
            return;
        }

        auto refFile = req.form.get_file("reference");
        auto capFile = req.form.get_file("captured");

        cv::Mat ref, cap;
        if (!read_image_from_upload(refFile, ref) || !read_image_from_upload(capFile, cap)) {
            res.status = 400;
            res.set_content(json_error("invalid_image"), "application/json");
            return;
        }

        cv::Mat grayRef, grayCap;
        cv::cvtColor(ref, grayRef, cv::COLOR_BGR2GRAY);
        cv::cvtColor(cap, grayCap, cv::COLOR_BGR2GRAY);

        cv::Mat faceRef, faceCap;
        if (!extract_face(grayRef, faceRef) || !extract_face(grayCap, faceCap)) {
            res.set_content(json_error("face_not_detected"), "application/json");
            return;
        }

        double hist = histogram_score(faceRef, faceCap);
        double orb = 0.0;
        bool hasOrb = orb_score(faceRef, faceCap, orb);
        double score = hasOrb ? (0.65 * hist + 0.35 * orb) : hist;
        score = clamp01(score);

        double threshold = kDefaultThreshold;
        if (req.has_param("threshold")) {
            try {
                threshold = std::stod(req.get_param_value("threshold"));
            } catch (...) {
                threshold = kDefaultThreshold;
            }
        }
        bool match = score >= threshold;
        res.set_content(json_ok(match, score, threshold), "application/json");
    });

    if (!svr.bind_to_port("0.0.0.0", port)) {
        std::cerr << "ERROR: no se pudo abrir el puerto " << port
                  << ". Verifica si ya está en uso o bloqueado por firewall.\n";
        return 1;
    }
    std::cout << "Face verify C++ server listening on 0.0.0.0:" << port << "\n";
    if (!svr.listen_after_bind()) {
        std::cerr << "ERROR: el servidor se detuvo inesperadamente.\n";
        return 1;
    }
    return 0;
}

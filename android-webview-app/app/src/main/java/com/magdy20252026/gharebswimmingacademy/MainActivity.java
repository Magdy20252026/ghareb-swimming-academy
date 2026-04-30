package com.magdy20252026.gharebswimmingacademy;

import android.Manifest;
import android.annotation.SuppressLint;
import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.view.View;
import android.webkit.CookieManager;
import android.webkit.PermissionRequest;
import android.webkit.URLUtil;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.ProgressBar;
import android.widget.Toast;

import java.util.ArrayList;
import java.util.LinkedHashSet;
import java.util.Locale;
import java.util.Set;

public class MainActivity extends Activity {
    private static final String INSTANCE_STATE_WEBVIEW = "webViewState";
    private static final int REQUEST_CAMERA_PERMISSION = 1001;
    private static final int REQUEST_FILE_CHOOSER = 1002;

    private WebView webView;
    private ProgressBar loadingIndicator;
    private ValueCallback<Uri[]> filePathCallback;
    private PermissionRequest pendingPermissionRequest;
    private PendingPermissionAction pendingPermissionAction = PendingPermissionAction.NONE;

    private enum PendingPermissionAction {
        NONE,
        WEB_CAMERA
    }

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        webView = findViewById(R.id.webView);
        loadingIndicator = findViewById(R.id.loadingIndicator);

        configureWebView();

        if (savedInstanceState != null) {
            Bundle webViewState = savedInstanceState.getBundle(INSTANCE_STATE_WEBVIEW);
            if (webViewState != null) {
                webView.restoreState(webViewState);
                return;
            }
        }

        webView.loadUrl(getString(R.string.webview_url));
    }

    @SuppressLint("SetJavaScriptEnabled")
    private void configureWebView() {
        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setDatabaseEnabled(true);
        settings.setMediaPlaybackRequiresUserGesture(false);
        settings.setLoadsImagesAutomatically(true);
        settings.setAllowFileAccess(true);
        settings.setAllowContentAccess(true);
        settings.setAllowFileAccessFromFileURLs(false);
        settings.setAllowUniversalAccessFromFileURLs(false);
        settings.setUseWideViewPort(true);
        settings.setLoadWithOverviewMode(true);
        settings.setBuiltInZoomControls(false);
        settings.setDisplayZoomControls(false);
        settings.setSupportMultipleWindows(false);
        settings.setCacheMode(WebSettings.LOAD_DEFAULT);

        CookieManager cookieManager = CookieManager.getInstance();
        cookieManager.setAcceptCookie(true);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            cookieManager.setAcceptThirdPartyCookies(webView, true);
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            settings.setSafeBrowsingEnabled(true);
        }

        webView.setScrollBarStyle(View.SCROLLBARS_INSIDE_OVERLAY);
        webView.setWebChromeClient(new AcademyWebChromeClient());
        webView.setWebViewClient(new AcademyWebViewClient());
    }

    private boolean hasCameraPermission() {
        return Build.VERSION.SDK_INT < Build.VERSION_CODES.M
            || checkSelfPermission(Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED;
    }

    private void requestCameraPermissionForWeb() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) {
            return;
        }
        pendingPermissionAction = PendingPermissionAction.WEB_CAMERA;
        requestPermissions(new String[]{Manifest.permission.CAMERA}, REQUEST_CAMERA_PERMISSION);
    }

    private boolean isTrustedOrigin(Uri origin) {
        if (origin == null) {
            return false;
        }

        String trustedHost = getString(R.string.webview_trusted_host).trim().toLowerCase(Locale.ROOT);
        String host = origin.getHost();
        return "https".equalsIgnoreCase(origin.getScheme())
            && host != null
            && host.toLowerCase(Locale.ROOT).equals(trustedHost);
    }

    private void grantWebCameraPermission(PermissionRequest request) {
        Set<String> allowedResources = new LinkedHashSet<>();
        for (String resource : request.getResources()) {
            if (PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(resource)) {
                allowedResources.add(resource);
            }
        }

        if (allowedResources.isEmpty()) {
            request.deny();
            return;
        }

        request.grant(allowedResources.toArray(new String[0]));
    }

    private String[] resolveAcceptedMimeTypes(WebChromeClient.FileChooserParams fileChooserParams) {
        ArrayList<String> normalizedTypes = new ArrayList<>();
        if (fileChooserParams == null) {
            return new String[0];
        }

        String[] acceptTypes = fileChooserParams.getAcceptTypes();
        if (acceptTypes == null || acceptTypes.length == 0) {
            return new String[0];
        }

        for (String acceptType : acceptTypes) {
            if (acceptType == null) {
                continue;
            }

            String normalizedType = acceptType.trim();
            if (!normalizedType.isEmpty() && normalizedType.contains("/")) {
                normalizedTypes.add(normalizedType);
            }
        }

        return normalizedTypes.toArray(new String[0]);
    }

    private String resolveMimeType(WebChromeClient.FileChooserParams fileChooserParams) {
        if (fileChooserParams == null) {
            return "*/*";
        }

        String[] acceptedMimeTypes = resolveAcceptedMimeTypes(fileChooserParams);
        return acceptedMimeTypes.length > 0 ? acceptedMimeTypes[0] : "*/*";
    }

    private void openExternalApp(Uri uri) {
        Intent intent = new Intent(Intent.ACTION_VIEW, uri);
        try {
            startActivity(intent);
        } catch (ActivityNotFoundException exception) {
            Toast.makeText(this, R.string.browser_open_failed, Toast.LENGTH_LONG).show();
        }
    }

    @Override
    protected void onSaveInstanceState(Bundle outState) {
        super.onSaveInstanceState(outState);
        Bundle webViewState = new Bundle();
        webView.saveState(webViewState);
        outState.putBundle(INSTANCE_STATE_WEBVIEW, webViewState);
    }

    @Override
    public void onBackPressed() {
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
            return;
        }
        super.onBackPressed();
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);

        if (requestCode != REQUEST_FILE_CHOOSER || filePathCallback == null) {
            return;
        }

        Uri[] results = null;
        if (resultCode == RESULT_OK) {
            if (data != null && data.getClipData() != null) {
                int itemCount = data.getClipData().getItemCount();
                results = new Uri[itemCount];
                for (int index = 0; index < itemCount; index++) {
                    results[index] = data.getClipData().getItemAt(index).getUri();
                }
            } else if (data != null && data.getData() != null) {
                results = new Uri[]{data.getData()};
            }
        }

        filePathCallback.onReceiveValue(results);
        filePathCallback = null;
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);

        if (requestCode != REQUEST_CAMERA_PERMISSION) {
            return;
        }

        boolean cameraGranted = grantResults.length > 0 && grantResults[0] == PackageManager.PERMISSION_GRANTED;
        if (cameraGranted && pendingPermissionAction == PendingPermissionAction.WEB_CAMERA && pendingPermissionRequest != null) {
            grantWebCameraPermission(pendingPermissionRequest);
        } else if (pendingPermissionRequest != null) {
            pendingPermissionRequest.deny();
            Toast.makeText(this, R.string.camera_permission_denied, Toast.LENGTH_LONG).show();
        }

        pendingPermissionRequest = null;
        pendingPermissionAction = PendingPermissionAction.NONE;
    }

    @Override
    protected void onDestroy() {
        if (webView != null) {
            webView.setWebChromeClient(null);
            webView.setWebViewClient(null);
            webView.destroy();
        }
        super.onDestroy();
    }

    private final class AcademyWebChromeClient extends WebChromeClient {
        @Override
        public void onPermissionRequest(final PermissionRequest request) {
            runOnUiThread(() -> {
                if (!isTrustedOrigin(request.getOrigin())) {
                    request.deny();
                    return;
                }

                boolean needsCamera = false;
                for (String resource : request.getResources()) {
                    if (PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(resource)) {
                        needsCamera = true;
                        break;
                    }
                }

                if (needsCamera && !hasCameraPermission()) {
                    pendingPermissionRequest = request;
                    requestCameraPermissionForWeb();
                    return;
                }

                grantWebCameraPermission(request);
            });
        }

        @Override
        public void onPermissionRequestCanceled(PermissionRequest request) {
            if (pendingPermissionRequest == request) {
                pendingPermissionRequest = null;
                pendingPermissionAction = PendingPermissionAction.NONE;
            }
        }

        @Override
        public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> filePathCallback,
                                         FileChooserParams fileChooserParams) {
            if (MainActivity.this.filePathCallback != null) {
                MainActivity.this.filePathCallback.onReceiveValue(null);
            }

            MainActivity.this.filePathCallback = filePathCallback;

            Intent pickFileIntent = new Intent(Intent.ACTION_GET_CONTENT);
            pickFileIntent.addCategory(Intent.CATEGORY_OPENABLE);
            pickFileIntent.setType(resolveMimeType(fileChooserParams));
            String[] acceptedMimeTypes = resolveAcceptedMimeTypes(fileChooserParams);
            if (acceptedMimeTypes.length > 1) {
                pickFileIntent.putExtra(Intent.EXTRA_MIME_TYPES, acceptedMimeTypes);
            }
            pickFileIntent.putExtra(Intent.EXTRA_ALLOW_MULTIPLE,
                fileChooserParams != null && fileChooserParams.getMode() == FileChooserParams.MODE_OPEN_MULTIPLE);

            Intent chooserIntent = Intent.createChooser(pickFileIntent, getString(R.string.file_chooser_title));

            try {
                startActivityForResult(chooserIntent, REQUEST_FILE_CHOOSER);
            } catch (ActivityNotFoundException exception) {
                MainActivity.this.filePathCallback = null;
                filePathCallback.onReceiveValue(null);
                Toast.makeText(MainActivity.this, R.string.file_chooser_failed, Toast.LENGTH_LONG).show();
            }

            return true;
        }

        @Override
        public void onProgressChanged(WebView view, int newProgress) {
            loadingIndicator.setVisibility(newProgress < 100 ? View.VISIBLE : View.GONE);
        }
    }

    private final class AcademyWebViewClient extends WebViewClient {
        @Override
        public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
            Uri uri = request.getUrl();
            String scheme = uri.getScheme();
            if (scheme == null) {
                return false;
            }

            if ("http".equalsIgnoreCase(scheme) || "https".equalsIgnoreCase(scheme)) {
                return false;
            }

            openExternalApp(uri);
            return true;
        }

        @Override
        public boolean shouldOverrideUrlLoading(WebView view, String url) {
            if (URLUtil.isNetworkUrl(url)) {
                return false;
            }

            openExternalApp(Uri.parse(url));
            return true;
        }

        @Override
        public void onPageFinished(WebView view, String url) {
            super.onPageFinished(view, url);
            loadingIndicator.setVisibility(View.GONE);
        }

        @Override
        public void onReceivedHttpError(WebView view, WebResourceRequest request, WebResourceResponse errorResponse) {
            super.onReceivedHttpError(view, request, errorResponse);
            if (request != null && request.isForMainFrame()) {
                Toast.makeText(MainActivity.this, R.string.web_loading_error, Toast.LENGTH_SHORT).show();
            }
        }
    }
}

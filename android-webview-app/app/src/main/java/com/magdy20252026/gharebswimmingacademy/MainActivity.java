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
import android.os.Message;
import android.provider.MediaStore;
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

import androidx.activity.OnBackPressedCallback;
import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.content.ContextCompat;
import androidx.core.content.FileProvider;

import java.io.File;
import java.io.IOException;
import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.LinkedHashSet;
import java.util.Locale;
import java.util.Map;
import java.util.Set;

public class MainActivity extends AppCompatActivity {
    private static final String INSTANCE_STATE_WEBVIEW = "webViewState";
    private static final String[] CAMERA_ONLY_PERMISSION = new String[]{Manifest.permission.CAMERA};

    private WebView webView;
    private ProgressBar loadingIndicator;
    private ValueCallback<Uri[]> filePathCallback;
    private Uri pendingCameraImageUri;
    private PermissionRequest pendingPermissionRequest;
    private Runnable pendingPermissionContinuation;

    private final ActivityResultLauncher<String[]> permissionLauncher = registerForActivityResult(
        new ActivityResultContracts.RequestMultiplePermissions(),
        this::handlePermissionResult
    );

    private final ActivityResultLauncher<Intent> fileChooserLauncher = registerForActivityResult(
        new ActivityResultContracts.StartActivityForResult(),
        result -> {
            if (filePathCallback == null) {
                clearPendingCameraUri();
                return;
            }

            Uri[] results = null;
            if (result.getResultCode() == Activity.RESULT_OK) {
                Intent data = result.getData();
                if (data != null && data.getClipData() != null) {
                    int itemCount = data.getClipData().getItemCount();
                    results = new Uri[itemCount];
                    for (int index = 0; index < itemCount; index++) {
                        results[index] = data.getClipData().getItemAt(index).getUri();
                    }
                } else if (data != null && data.getData() != null) {
                    results = new Uri[]{data.getData()};
                } else if (pendingCameraImageUri != null) {
                    results = new Uri[]{pendingCameraImageUri};
                }
            }

            filePathCallback.onReceiveValue(results);
            filePathCallback = null;
            clearPendingCameraUri();
        }
    );

    @Override
    protected void onCreate(@Nullable Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        webView = findViewById(R.id.webView);
        loadingIndicator = findViewById(R.id.loadingIndicator);

        configureWebView();
        handleBackNavigation();

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
        cookieManager.setAcceptThirdPartyCookies(webView, true);

        webView.setScrollBarStyle(View.SCROLLBARS_INSIDE_OVERLAY);
        webView.setWebChromeClient(new AcademyWebChromeClient());
        webView.setWebViewClient(new AcademyWebViewClient());

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            webView.getSettings().setSafeBrowsingEnabled(true);
        }
    }

    private void handleBackNavigation() {
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override
            public void handleOnBackPressed() {
                if (webView.canGoBack()) {
                    webView.goBack();
                } else {
                    finish();
                }
            }
        });
    }

    private void handlePermissionResult(@NonNull Map<String, Boolean> result) {
        boolean cameraGranted = Boolean.TRUE.equals(result.get(Manifest.permission.CAMERA));

        if (cameraGranted && pendingPermissionContinuation != null) {
            Runnable continuation = pendingPermissionContinuation;
            pendingPermissionContinuation = null;
            continuation.run();
            return;
        }

        if (pendingPermissionRequest != null) {
            pendingPermissionRequest.deny();
            pendingPermissionRequest = null;
        }

        if (filePathCallback != null) {
            filePathCallback.onReceiveValue(null);
            filePathCallback = null;
            clearPendingCameraUri();
        }

        pendingPermissionContinuation = null;
        Toast.makeText(this, R.string.camera_permission_denied, Toast.LENGTH_LONG).show();
    }

    private void clearPendingCameraUri() {
        pendingCameraImageUri = null;
    }

    private boolean hasCameraPermission() {
        return ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED;
    }

    private boolean isTrustedOrigin(@Nullable Uri origin) {
        if (origin == null) {
            return false;
        }

        String trustedHost = getString(R.string.webview_trusted_host).trim().toLowerCase(Locale.ROOT);
        String scheme = origin.getScheme();
        String host = origin.getHost();
        return "https".equalsIgnoreCase(scheme)
            && host != null
            && host.toLowerCase(Locale.ROOT).equals(trustedHost);
    }

    private void grantWebCameraPermission(@NonNull PermissionRequest request) {
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

    private boolean acceptsImages(@Nullable WebChromeClient.FileChooserParams fileChooserParams) {
        if (fileChooserParams == null) {
            return true;
        }

        String[] acceptTypes = fileChooserParams.getAcceptTypes();
        if (acceptTypes == null || acceptTypes.length == 0) {
            return true;
        }

        for (String acceptType : acceptTypes) {
            if (acceptType == null || acceptType.isBlank()) {
                return true;
            }

            String normalizedType = acceptType.toLowerCase(Locale.ROOT);
            if (normalizedType.contains("image") || normalizedType.equals("*/*")) {
                return true;
            }
        }

        return false;
    }

    @NonNull
    private Intent buildContentSelectionIntent(@Nullable WebChromeClient.FileChooserParams fileChooserParams) {
        Intent intent = new Intent(Intent.ACTION_GET_CONTENT);
        intent.addCategory(Intent.CATEGORY_OPENABLE);
        intent.putExtra(Intent.EXTRA_ALLOW_MULTIPLE,
            fileChooserParams != null && fileChooserParams.getMode() == WebChromeClient.FileChooserParams.MODE_OPEN_MULTIPLE);
        intent.setType(resolveMimeType(fileChooserParams));
        return intent;
    }

    @NonNull
    private String resolveMimeType(@Nullable WebChromeClient.FileChooserParams fileChooserParams) {
        if (fileChooserParams == null) {
            return "image/*";
        }

        String[] acceptTypes = fileChooserParams.getAcceptTypes();
        if (acceptTypes == null || acceptTypes.length == 0) {
            return "image/*";
        }

        for (String acceptType : acceptTypes) {
            if (acceptType != null && !acceptType.isBlank()) {
                return acceptType;
            }
        }

        return "image/*";
    }

    @Nullable
    private Intent buildCameraIntent() {
        Intent captureIntent = new Intent(MediaStore.ACTION_IMAGE_CAPTURE);
        if (captureIntent.resolveActivity(getPackageManager()) == null) {
            return null;
        }

        File imageFile;
        try {
            imageFile = createCameraImageFile();
        } catch (IOException exception) {
            return null;
        }

        pendingCameraImageUri = FileProvider.getUriForFile(
            this,
            getPackageName() + ".fileprovider",
            imageFile
        );
        captureIntent.putExtra(MediaStore.EXTRA_OUTPUT, pendingCameraImageUri);
        captureIntent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_GRANT_WRITE_URI_PERMISSION);
        return captureIntent;
    }

    @NonNull
    private File createCameraImageFile() throws IOException {
        File cacheParent = getExternalCacheDir();
        if (cacheParent == null) {
            cacheParent = getCacheDir();
        }
        File cameraDirectory = new File(cacheParent, "camera");
        if (!cameraDirectory.exists() && !cameraDirectory.mkdirs()) {
            throw new IOException("Unable to create camera cache directory");
        }

        String timestamp = new SimpleDateFormat("yyyyMMdd_HHmmss", Locale.US).format(new Date());
        return File.createTempFile("attendance_" + timestamp + "_", ".jpg", cameraDirectory);
    }

    private void openFileChooser(@Nullable WebChromeClient.FileChooserParams fileChooserParams) {
        Intent contentIntent = buildContentSelectionIntent(fileChooserParams);
        ArrayList<Intent> extraIntents = new ArrayList<>();

        if (acceptsImages(fileChooserParams)) {
            Intent cameraIntent = buildCameraIntent();
            if (cameraIntent != null) {
                extraIntents.add(cameraIntent);
            }
        }

        Intent chooserIntent = Intent.createChooser(contentIntent, getString(R.string.file_chooser_title));
        chooserIntent.putExtra(Intent.EXTRA_INITIAL_INTENTS, extraIntents.toArray(new Intent[0]));

        try {
            fileChooserLauncher.launch(chooserIntent);
        } catch (ActivityNotFoundException exception) {
            if (filePathCallback != null) {
                filePathCallback.onReceiveValue(null);
                filePathCallback = null;
            }
            clearPendingCameraUri();
            Toast.makeText(this, R.string.file_chooser_failed, Toast.LENGTH_LONG).show();
        }
    }

    private void openExternalApp(@NonNull Uri uri) {
        Intent intent = new Intent(Intent.ACTION_VIEW, uri);
        try {
            startActivity(intent);
        } catch (ActivityNotFoundException exception) {
            Toast.makeText(this, R.string.browser_open_failed, Toast.LENGTH_LONG).show();
        }
    }

    @Override
    protected void onSaveInstanceState(@NonNull Bundle outState) {
        super.onSaveInstanceState(outState);
        Bundle webViewState = new Bundle();
        webView.saveState(webViewState);
        outState.putBundle(INSTANCE_STATE_WEBVIEW, webViewState);
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
                    pendingPermissionContinuation = () -> {
                        grantWebCameraPermission(request);
                        pendingPermissionRequest = null;
                    };
                    permissionLauncher.launch(CAMERA_ONLY_PERMISSION);
                    return;
                }

                grantWebCameraPermission(request);
            });
        }

        @Override
        public void onPermissionRequestCanceled(PermissionRequest request) {
            if (pendingPermissionRequest == request) {
                pendingPermissionRequest = null;
                pendingPermissionContinuation = null;
            }
        }

        @Override
        public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> filePathCallback,
                                         FileChooserParams fileChooserParams) {
            if (MainActivity.this.filePathCallback != null) {
                MainActivity.this.filePathCallback.onReceiveValue(null);
            }

            MainActivity.this.filePathCallback = filePathCallback;
            Runnable showChooser = () -> openFileChooser(fileChooserParams);

            if (acceptsImages(fileChooserParams) && !hasCameraPermission()) {
                pendingPermissionContinuation = showChooser;
                permissionLauncher.launch(CAMERA_ONLY_PERMISSION);
                return true;
            }

            showChooser.run();
            return true;
        }

        @Override
        public void onProgressChanged(WebView view, int newProgress) {
            loadingIndicator.setVisibility(newProgress < 100 ? View.VISIBLE : View.GONE);
        }

        @Override
        public boolean onCreateWindow(WebView view, boolean isDialog, boolean isUserGesture, Message resultMsg) {
            WebView.HitTestResult result = view.getHitTestResult();
            if (result != null && result.getExtra() != null) {
                String targetUrl = result.getExtra();
                if (URLUtil.isNetworkUrl(targetUrl)) {
                    view.loadUrl(targetUrl);
                    return false;
                }
            }
            return false;
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
            if (request.isForMainFrame()) {
                Toast.makeText(MainActivity.this, R.string.web_loading_error, Toast.LENGTH_SHORT).show();
            }
        }
    }
}

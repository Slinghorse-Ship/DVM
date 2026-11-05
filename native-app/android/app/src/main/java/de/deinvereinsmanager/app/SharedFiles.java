package de.deinvereinsmanager.app;

import android.content.ContentProvider;
import android.content.ContentValues;
import android.database.Cursor;
import android.database.MatrixCursor;
import android.net.Uri;
import android.os.ParcelFileDescriptor;
import android.provider.OpenableColumns;
import java.io.File;
import java.io.FileNotFoundException;

/** Only completed, explicitly shared application-cache copies can be opened. */
public final class SharedFiles extends ContentProvider {
    @Override public boolean onCreate() { return true; }
    private File resolve(Uri uri) throws FileNotFoundException {
        try {
            if (uri.getPathSegments().size() != 2 || !uri.getPathSegments().get(0).matches("[a-f0-9]{32}")) throw new Exception();
            File root = new File(getContext().getCacheDir(), "shared").getCanonicalFile();
            File file = new File(new File(root, uri.getPathSegments().get(0)), uri.getLastPathSegment()).getCanonicalFile();
            if (!file.getParentFile().getParentFile().equals(root) || !file.isFile()) throw new Exception();
            return file;
        } catch (Exception invalid) { throw new FileNotFoundException(getContext().getString(R.string.native_file_unavailable)); }
    }
    @Override public ParcelFileDescriptor openFile(Uri uri, String mode) throws FileNotFoundException {
        if (!mode.equals("r")) throw new FileNotFoundException(getContext().getString(R.string.native_only_reading_is_allowed));
        return ParcelFileDescriptor.open(resolve(uri), ParcelFileDescriptor.MODE_READ_ONLY);
    }
    @Override public Cursor query(Uri uri, String[] projection, String selection, String[] args, String sort) {
        try {
            File file = resolve(uri);
            MatrixCursor result = new MatrixCursor(new String[]{OpenableColumns.DISPLAY_NAME, OpenableColumns.SIZE});
            result.addRow(new Object[]{file.getName(), file.length()}); return result;
        } catch (FileNotFoundException absent) { return null; }
    }
    @Override public String getType(Uri uri) {
        try {String name=resolve(uri).getName();int dot=name.lastIndexOf('.');String type=dot<0?null:android.webkit.MimeTypeMap.getSingleton().getMimeTypeFromExtension(name.substring(dot+1).toLowerCase(java.util.Locale.ROOT));return type==null?"application/octet-stream":type;}
        catch(FileNotFoundException absent){return null;}
    }
    @Override public Uri insert(Uri uri, ContentValues values) { throw new UnsupportedOperationException(); }
    @Override public int delete(Uri uri, String selection, String[] args) { throw new UnsupportedOperationException(); }
    @Override public int update(Uri uri, ContentValues values, String selection, String[] args) { throw new UnsupportedOperationException(); }
}

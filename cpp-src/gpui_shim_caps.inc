// cpp-src/gpui_shim_caps.inc —— 授权文件系统 / 进程 / 存储（Capabilities）
//
// 这是 gpui_shim.cpp 的**文本切片**，不是独立编译单元：由主 TU 在原位置 #include 进来。
// 为什么不走"拆成 .cpp"那条路：gpui.h 是 32 万行的 amalgam，每多一个包含它的 TU
// 就多付一次全量编译；而拆文件的目的只是"少一屏要同时装在脑子里的东西"，
// 不是为了链接结构。语义、符号可见性、编译次数都不变。
//
// 这里的东西只能被 gpui_shim.cpp include（依赖它前面的 g_app / cstr / cppStr / Box2 等）。

// ── 授权文件系统 / 进程 / 存储（Capabilities）────────────────────────────────
//
// 进程级一份 gpui::Capabilities：gpui 的 FsRun 要 (root, relative) 两半，root 必须是
// 事先授予过的目录，所以"授权"这件事天然是 app 级而不是窗口级。
//
// scratch 的生命周期是**到下一次调用为止**：桥在同一个调用栈里立刻拷走，PHP 侧拿到的
// 是 php::String 的副本。列目录的名字用 vector<string> 先建完再回指针，避免 push_back
// 触发搬移让先前给出去的 c_str() 悬垂。

namespace {

gpui::Capabilities g_caps;
gpui::shell::Storage* g_storage = nullptr;
std::string g_scratch;     // 上一次成功调用的字节
std::string g_scratchErr;  // 进程 stderr
std::vector<std::string> g_fsNames;

gpui::shell::Storage* StorageNow() {
    if (!g_storage) g_storage = new gpui::shell::Storage(true);
    return g_storage;
}

// CapabilityError → 人话。不借 CapabilityErrorMessage（它要 Arena，而这里没有帧）。
void SetError(char* err, int errCap, const char* msg) {
    if (err && errCap > 0) copyOut(err, errCap, gpui::Str(msg, (int)std::strlen(msg)));
}

void SetCapError(char* err, int errCap, const gpui::CapabilityError& ce) {
    const char* kind = "被拒";
    switch (ce.kind) {
        case gpui::CapabilityErrorKind::NotGranted: kind = "没有这项授权"; break;
        case gpui::CapabilityErrorKind::OutsideRoots: kind = "路径不在授予的根里"; break;
        case gpui::CapabilityErrorKind::ExecuteDenied: kind = "命令不在白名单里"; break;
        case gpui::CapabilityErrorKind::StorageDenied: kind = "没有存储授权"; break;
        default: break;
    }
    std::string msg = std::string("gpui 授权拒绝（") + kind + "）: " +
                      (ce.subject.s ? std::string(ce.subject.s, (size_t)ce.subject.len) : std::string());
    SetError(err, errCap, msg.c_str());
}

// 选根：把用户给的路径拆成 (root, relative)。false 时已写好 err。
bool Resolve(const char* path, gpui::CapabilityAccess access,
             gpui::CapabilityPath* out, char* err, int errCap) {
    gpui::CapabilityError ce;
    if (!g_caps.ResolvePath(cstr(path), access, out, &ce)) {
        SetCapError(err, errCap, ce);
        out->Free();
        return false;
    }
    return true;
}

void SetFsError(char* err, int errCap, gpui::Str ferr) {
    if (!err || errCap <= 0) return;
    if (ferr.s && ferr.len > 0) {
        copyOut(err, errCap, ferr);
        return;
    }
    SetError(err, errCap, "gpui FsRun 失败");
}

}  // namespace

int gpui_shim_grant_root(const char* root, int writable) {
    if (!root || !*root) return 0;
    if (writable) {
        // gpui 把读根与写根当两张表（ResolvePath 按 access 只查对应那张），但"能写不能读"
        // 的根没有用处，所以 writable=true 两张都加。
        g_caps.AddReadRoot(cstr(root));
        g_caps.AddWriteRoot(cstr(root));
        return g_caps.HasWriteAccess() ? 1 : 0;
    }
    g_caps.AddReadRoot(cstr(root));
    return g_caps.HasReadAccess() ? 1 : 0;
}

int gpui_shim_grant_execute(const char* const* commands, int count,
                            int unrestricted) {
    if (unrestricted) {
        g_caps.SetExecute(gpui::ExecuteGrant::Unrestricted());
        return 1;
    }
    std::vector<gpui::Str> list;
    for (int i = 0; i < count && commands; i++) list.push_back(cstr(commands[i]));
    g_caps.SetExecute(gpui::ExecuteGrant::Allowed(
        list.empty() ? nullptr : list.data(), (int)list.size()));
    return 1;
}

void gpui_shim_grant_storage(int allowed) { g_caps.Storage(allowed != 0); }

int gpui_shim_storage_granted(void) { return g_caps.HasStorage() ? 1 : 0; }

int gpui_shim_storage_path(const char* path, char* err, int err_cap) {
    gpui::Str perr;
    if (!StorageNow()->SetPath(cstr(path), &perr)) {
        SetFsError(err, err_cap, perr);
        return 0;
    }
    return 1;
}

int gpui_shim_fs_read(const char* path, char* err, int err_cap) {
    gpui::CapabilityPath cp;
    if (!Resolve(path, gpui::CapabilityAccess::Read, &cp, err, err_cap)) return 0;
    gpui::shell::FsResult res;
    gpui::Str ferr;
    const bool ok = gpui::shell::FsRun(gpui::shell::FsOperation::Read, cp.root,
                                       cp.relative, gpui::Str{}, false, &res, &ferr);
    cp.Free();
    if (!ok) {
        SetFsError(err, err_cap, ferr);
        res.Free();
        return 0;
    }
    g_scratch.assign(res.bytes.s ? res.bytes.s : "", (size_t)(res.bytes.len > 0 ? res.bytes.len : 0));
    res.Free();
    return 1;
}

int gpui_shim_fs_write(const char* path, const char* data, int len, char* err,
                       int err_cap) {
    gpui::CapabilityPath cp;
    if (!Resolve(path, gpui::CapabilityAccess::Write, &cp, err, err_cap)) return 0;
    gpui::shell::FsResult res;
    gpui::Str ferr;
    const bool ok = gpui::shell::FsRun(gpui::shell::FsOperation::Write, cp.root,
                                       cp.relative,
                                       gpui::Str(data ? data : "", len > 0 ? len : 0),
                                       false, &res, &ferr);
    cp.Free();
    if (!ok) SetFsError(err, err_cap, ferr);
    res.Free();
    return ok ? 1 : 0;
}

int gpui_shim_fs_list(const char* path, GpuiFsEntry* entries, int cap,
                      int* out_count, char* err, int err_cap) {
    gpui::CapabilityPath cp;
    if (!Resolve(path, gpui::CapabilityAccess::Read, &cp, err, err_cap)) return 0;
    gpui::shell::FsResult res;
    gpui::Str ferr;
    const bool ok = gpui::shell::FsRun(gpui::shell::FsOperation::ReadDirectory,
                                       cp.root, cp.relative, gpui::Str{}, false,
                                       &res, &ferr);
    cp.Free();
    if (!ok) {
        SetFsError(err, err_cap, ferr);
        res.Free();
        return 0;
    }
    // 先把名字全建完，再回指针：边 push_back 边给 c_str() 会在扩容时悬垂。
    g_fsNames.clear();
    g_fsNames.reserve((size_t)res.entries.len);
    for (int i = 0; i < res.entries.len; i++) {
        gpui::Str n = res.entries.els[i].name;
        g_fsNames.emplace_back(n.s ? n.s : "", (size_t)(n.len > 0 ? n.len : 0));
    }
    const int n = std::min<int>(cap, (int)g_fsNames.size());
    for (int i = 0; i < n && entries; i++) {
        entries[i].name = g_fsNames[(size_t)i].c_str();
        entries[i].is_dir = res.entries.els[i].isDirectory ? 1 : 0;
    }
    if (out_count) *out_count = (int)g_fsNames.size();
    res.Free();
    return 1;
}

int gpui_shim_fs_exists(const char* path) {
    gpui::CapabilityPath cp;
    if (!Resolve(path, gpui::CapabilityAccess::Read, &cp, nullptr, 0)) return 0;
    gpui::shell::FsResult res;
    gpui::Str ferr;
    const bool ok = gpui::shell::FsRun(gpui::shell::FsOperation::Exists, cp.root,
                                       cp.relative, gpui::Str{}, false, &res, &ferr);
    cp.Free();
    const bool exists = ok && res.exists;
    res.Free();
    return exists ? 1 : 0;
}

// gpui 的 RemoveDirectory 就是 unlinkat(AT_REMOVEDIR)，非空目录会失败：recursive
// 只对 MakeDirectory 生效（filesystem_posix.cpp:359-371）。所以"递归删"要自己搭，
// 而且只能用同一套授权遍历——列目录、逐个删、最后删自己，不碰 ambient 路径。
static bool RemoveTree(const gpui::CapabilityPath& cp, const char* rel, int depth,
                       char* err, int err_cap) {
    if (depth > 32) {
        SetError(err, err_cap, "gpui 删除递归过深（>32 层）");
        return false;
    }
    gpui::shell::FsResult listing;
    gpui::Str lerr;
    if (!gpui::shell::FsRun(gpui::shell::FsOperation::ReadDirectory, cp.root,
                            gpui::Str(rel, (int)std::strlen(rel)), gpui::Str{}, false,
                            &listing, &lerr)) {
        // 空目录/不存在都到这里；把底层原因交回去。
        SetFsError(err, err_cap, lerr);
        listing.Free();
        return false;
    }
    const std::string base = rel;
    bool ok = true;
    for (int i = 0; ok && i < listing.entries.len; i++) {
        gpui::Str n = listing.entries.els[i].name;
        const bool isDir = listing.entries.els[i].isDirectory;
        std::string child = base.empty() ? std::string(n.s, (size_t)n.len)
                                         : base + "/" + std::string(n.s, (size_t)n.len);
        gpui::shell::FsResult drop;
        gpui::Str derr;
        if (isDir) {
            ok = RemoveTree(cp, child.c_str(), depth + 1, err, err_cap);
            continue;
        }
        ok = gpui::shell::FsRun(gpui::shell::FsOperation::RemoveFile, cp.root,
                               gpui::Str(child.data(), (int)child.size()), gpui::Str{},
                               false, &drop, &derr);
        if (!ok) SetFsError(err, err_cap, derr);
        drop.Free();
    }
    listing.Free();
    return ok;
}

int gpui_shim_fs_remove(const char* path, int dir, int recursive, char* err,
                        int err_cap) {
    gpui::CapabilityPath cp;
    if (!Resolve(path, gpui::CapabilityAccess::Write, &cp, err, err_cap)) return 0;
    std::string rel = cp.relative.s ? std::string(cp.relative.s, (size_t)cp.relative.len)
                                    : std::string();
    bool ok = true;
    if (dir && recursive) ok = RemoveTree(cp, rel.c_str(), 0, err, err_cap);
    gpui::shell::FsResult res;
    gpui::Str ferr;
    if (ok) {
        ok = gpui::shell::FsRun(
            dir ? gpui::shell::FsOperation::RemoveDirectory
                : gpui::shell::FsOperation::RemoveFile,
            cp.root, cp.relative, gpui::Str{}, false, &res, &ferr);
        if (!ok) SetFsError(err, err_cap, ferr);
    }
    res.Free();
    cp.Free();
    return ok ? 1 : 0;
}

int gpui_shim_fs_mkdir(const char* path, int dir, char* err, int err_cap) {
    gpui::CapabilityPath cp;
    if (!Resolve(path, gpui::CapabilityAccess::Write, &cp, err, err_cap)) return 0;
    gpui::shell::FsResult res;
    gpui::Str ferr;
    const bool ok = gpui::shell::FsRun(gpui::shell::FsOperation::MakeDirectory,
                                       cp.root, cp.relative, gpui::Str{},
                                       dir != 0, &res, &ferr);
    cp.Free();
    if (!ok) SetFsError(err, err_cap, ferr);
    res.Free();
    return ok ? 1 : 0;
}

void gpui_shim_out(const char** data, int* len) {
    if (data) *data = g_scratch.empty() ? nullptr : g_scratch.data();
    if (len) *len = (int)g_scratch.size();
}

int gpui_shim_process_run(const char* command, const char* const* args,
                          int count, int* out_code, char* err, int err_cap) {
    gpui::Str cmd = cstr(command);
    if (!g_caps.MayRun(cmd)) {
        gpui::CapabilityError ce;
        ce.kind = gpui::CapabilityErrorKind::ExecuteDenied;
        ce.subject = cmd;
        SetCapError(err, err_cap, ce);
        return 0;
    }
    std::vector<gpui::Str> list;
    for (int i = 0; i < count && args; i++) list.push_back(cstr(args[i]));
    gpui::shell::ProcessCancellation cancel;
    gpui::shell::ProcessOutput po;
    gpui::Str perr;
    const bool ok = gpui::shell::ProcessRunBounded(
        cmd, list.empty() ? nullptr : list.data(), (int)list.size(), &cancel, &po,
        &perr);
    if (!ok) {
        SetFsError(err, err_cap, perr);
        po.Free();
        return 0;
    }
    g_scratch.assign(po.out.s ? po.out.s : "", (size_t)(po.out.len > 0 ? po.out.len : 0));
    g_scratchErr.assign(po.err.s ? po.err.s : "", (size_t)(po.err.len > 0 ? po.err.len : 0));
    if (out_code) *out_code = po.code;
    po.Free();
    return 1;
}

void gpui_shim_process_err(const char** data, int* len) {
    if (data) *data = g_scratchErr.empty() ? nullptr : g_scratchErr.data();
    if (len) *len = (int)g_scratchErr.size();
}

int gpui_shim_storage_set(const char* key, const char* data, int len, char* err,
                          int err_cap) {
    if (!g_caps.HasStorage()) {
        gpui::CapabilityError ce;
        ce.kind = gpui::CapabilityErrorKind::StorageDenied;
        ce.subject = cstr(key);
        SetCapError(err, err_cap, ce);
        return 0;
    }
    gpui::Str serr;
    gpui::shell::Storage* st = StorageNow();
    if (!st->Set(cstr(key), gpui::Str(data ? data : "", len > 0 ? len : 0), &serr)) {
        SetFsError(err, err_cap, serr);
        return 0;
    }
    // 落盘是 BeginWrite → StoragePersist → FinishWrite 三步（runtime.cpp:1190 的
    // DriveStorage 就是这一串）。不 persist 的话 Set 只改内存。
    gpui::shell::StorageWrite w;
    gpui::Str berr;
    if (st->BeginWrite(&w, &berr) && w.revision) {
        gpui::Str perr;
        const bool ok = gpui::shell::StoragePersist(w, &perr);
        // 我们从不注册 waiter，所以 ready 列表传空即可。
        st->FinishWrite(w.revision, ok, nullptr);
        w.Free();
        if (!ok) SetFsError(err, err_cap, perr);
    } else {
        SetFsError(err, err_cap, berr);
        w.Free();
    }
    return 1;
}

int gpui_shim_storage_get(const char* key) {
    if (!g_caps.HasStorage()) return 0;
    gpui::Str v = StorageNow()->Get(cstr(key));
    if (!v.s) return 0;
    g_scratch.assign(v.s, (size_t)(v.len > 0 ? v.len : 0));
    return 1;
}

int gpui_shim_storage_remove(const char* key, char* err, int err_cap) {
    if (!g_caps.HasStorage()) return 0;
    gpui::Str serr;
    const bool ok = StorageNow()->Remove(cstr(key), &serr);
    if (!ok) SetFsError(err, err_cap, serr);
    return ok ? 1 : 0;
}

int gpui_shim_storage_len(void) {
    return g_caps.HasStorage() ? StorageNow()->Len() : 0;
}

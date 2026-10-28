// cpp-src/gpui_data_node.inc —— GpuiDataNode 的宿主侧所有权（文本切片）
//
// 为什么单独一片：gpui_data_node_free 是 shim 走完树后调的宿主符号（gpui_shim.h），
// 生产定义原本只在 gpui_bridge.cc 里，而 bridge 依赖 phpx，headless 的
// tests/cpp/shim_test.cpp 链不进来 ⇒ 整个 shim TU 缺这一个符号、test-shim 链接失败。
// 这片不碰 phpx，两侧 include 同一份生产代码（不抄副本）。
// include 位置：bridge 在 FillNode 之前（它用 DataAlloc）；shim_test 在 gpui_shim.cpp 之后。

// 分配的两个硬约束：
//  ① 数组的 items 字段被消费方（shim 的 DataNodeToValue、gpui_data_node_free）
//     按连续数组索引（items[i]）——子节点必须**连续分配**（new GpuiDataNode[n]），
//     各自 new 出来在堆上不连续，索引即踩空（实测）。
//  ② 字符串指针必须活到 shim 转换完——每个字符串单独堆分配（vector 只存指针，
//     扩容动指针不动字符串）。
struct DataAlloc {
    std::vector<char*> strings;
    std::vector<GpuiDataNode*> arrays;  // 每个数组节点的连续块（供 free 遍历）
    const char* AddString(const std::string& s) {
        char* p = new char[s.size() + 1];
        std::memcpy(p, s.data(), s.size());
        p[s.size()] = '\0';
        strings.push_back(p);
        return p;
    }
    const char** AddKeys(const std::vector<std::string>& ks) {
        auto* ptrs = new const char*[ks.size() + 1];
        for (size_t i = 0; i < ks.size(); i++) ptrs[i] = AddString(ks[i]);
        ptrs[ks.size()] = nullptr;
        return ptrs;
    }
};

// root → 归属的 DataAlloc。shim 走完树调用 gpui_data_node_free 时按 root 找回
// 分配器一并释放——DataAlloc 是 bridge 侧的堆对象，函数局部的话 hook 返回即悬垂。
static std::unordered_map<GpuiDataNode*, DataAlloc*> g_dataAllocs;

void gpui_data_node_free(GpuiDataNode* node) {
    if (!node) return;
    // 先走树（items 是连续块，items[i] 索引成立；keys 仍有效）。
    // **块内的子节点不单独 delete**——它们是 `new GpuiDataNode[n]` 一块的
    // 元素，单独 delete 是 UB（实测：STR 在块首，delete STR 把整块释放，
    // 后面的 NUM 读到已释放内存）。块与字符串只在根处（g_dataAllocs 命中）
    // 统一释放；根节点本身是单独 new 的，一并删。
    if (node->kind == GPUI_ARG_ARRAY && node->items) {
        for (int i = 0; i < node->count; i++)
            gpui_data_node_free(&node->items[i]);
        delete[] node->keys;
    }
    auto it = g_dataAllocs.find(node);
    if (it != g_dataAllocs.end()) {
        DataAlloc* al = it->second;
        for (char* p : al->strings) delete[] p;
        for (GpuiDataNode* block : al->arrays) delete[] block;
        delete al;
        g_dataAllocs.erase(it);
        delete node;
    }
}
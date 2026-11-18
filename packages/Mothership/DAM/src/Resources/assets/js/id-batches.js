// Splits a list of ids into batches the server accepts (NewUploadsController takes at most 1,000 per request).
(function (root) {
    const MAX_IDS = 1000;
    function chunkIds(ids, size = MAX_IDS) {
        const batches = [];
        for (let i = 0; i < ids.length; i += size) batches.push(ids.slice(i, i + size));
        return batches;
    }
    const api = {MAX_IDS, chunkIds};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.MothershipIdBatches = api;
})(typeof window !== 'undefined' ? window : globalThis);

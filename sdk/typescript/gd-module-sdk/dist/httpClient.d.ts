export declare function request<T = any>(method: string, urlStr: string, headers?: Record<string, string>, body?: any): Promise<T>;
export declare function get<T = any>(url: string, headers?: Record<string, string>): Promise<T>;
export declare function post<T = any>(url: string, body: any, headers?: Record<string, string>): Promise<T>;

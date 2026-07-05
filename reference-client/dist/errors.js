export function normalizeSdkError(error) {
    if (error instanceof Error) {
        return error.message;
    }
    if (typeof error === 'string') {
        return error;
    }
    if (typeof error === 'object' && error !== null) {
        const axiosError = error;
        if (axiosError.response) {
            return `API error ${axiosError.response.status}: ${JSON.stringify(axiosError.response.data)}`;
        }
        return JSON.stringify(error);
    }
    return 'Unknown error';
}
